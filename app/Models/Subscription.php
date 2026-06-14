<?php

namespace App\Models;

use App\Enums\BillingPeriod;
use App\Enums\PlanItemType;
use App\Enums\SubscriptionPaymentStatus;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Subscription extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'business_name',
        'business_type_id',
        'commercial_name',
        'status',
        'contact_phone',
        'contact_email',
        'tax_id',
        'address',
        'slug',
        'onboarding_completed_at',
    ];

    protected $casts = [
        'address' => 'array',
        'onboarding_completed_at' => 'datetime',
        'status' => SubscriptionStatus::class,
    ];
    
    protected $appends = [
        'computed_status',
    ];

    /**
     * Evalúa el estado real al vuelo basado en la vigencia de la suscripción.
     */
    public function getComputedStatusAttribute(): string
    {
        // A) Evitar N+1: Verificamos si existe la propiedad inyectada por subconsulta en los listados (Index)
        if (array_key_exists('latest_version_end_date', $this->attributes)) {
            if (!$this->latest_version_end_date) {
                return SubscriptionStatus::SUSPENDED->value;
            }
            $endDate = Carbon::parse($this->latest_version_end_date)->startOfDay();
            return $endDate->isPast() ? SubscriptionStatus::EXPIRED->value : SubscriptionStatus::ACTIVE->value;
        }

        // B) Fallback: Si se consulta un modelo individual (Ej: Detalles / Show)
        $latestVersion = $this->relationLoaded('versions') 
            ? $this->versions->sortByDesc('id')->first() 
            : $this->versions()->latest('id')->first();

        if (!$latestVersion) {
            return SubscriptionStatus::SUSPENDED->value;
        }

        $endDate = Carbon::parse($latestVersion->end_date)->startOfDay();
        return $endDate->isPast() ? SubscriptionStatus::EXPIRED->value : SubscriptionStatus::ACTIVE->value;
    }
    /*
    |--------------------------------------------------------------------------
    | LÓGICA DE NEGOCIO Y HELPERS (REFACTOR)
    |--------------------------------------------------------------------------
    */

    public function currentVersion()
    {
        return $this->versions()
            ->where('start_date', '<=', now())
            ->where('end_date', '>=', now()->startOfDay())
            ->latest('id')
            ->first();
    }

    public function getAvailableModuleNames(): array
    {
        $currentVersion = $this->currentVersion();
        if (!$currentVersion) return [];

        $subscribedModuleKeys = $currentVersion->items()->where('item_type', 'module')->pluck('item_key')->all();
        return PlanItem::whereIn('key', $subscribedModuleKeys)->where('type', PlanItemType::MODULE)->pluck('name')->all();
    }

    public function hasReachedProductLimit(int $additionalItems = 0): bool
    {
        $currentVersion = $this->currentVersion();
        $limitItem = $currentVersion ? $currentVersion->items()->where('item_key', 'limit_products')->first() : null;
        $limitProducts = $limitItem ? $limitItem->quantity : 50;

        if ($limitProducts === -1) return false;

        return ($this->products_count + $additionalItems) > $limitProducts;
    }

    /**
     * Determina si la suscripción ha alcanzado el límite de servicios.
     */
    public function hasReachedServiceLimit(int $additionalItems = 0): bool
    {
        $currentVersion = $this->currentVersion();
        $limitItem = $currentVersion ? $currentVersion->items()->where('item_key', 'limit_services')->first() : null;
        $limitServices = $limitItem ? $limitItem->quantity : 100;

        if ($limitServices === -1) {
            return false; // -1 significa ilimitado
        }

        return ($this->services_count + $additionalItems) > $limitServices;
    }

    /**
     * Obtiene los datos de límite y uso de usuarios para la suscripción actual.
     */
    public function getUserLimitData(): array
    {
        $currentVersion = $this->versions()->latest('start_date')->first();
        $limit = -1; // -1 significa ilimitado
        
        if ($currentVersion) {
            $limitItem = $currentVersion->items()->where('item_key', 'limit_users')->first();
            if ($limitItem) {
                $limit = $limitItem->quantity;
            }
        }
        
        $usage = $this->users()->count();
        
        return ['limit' => $limit, 'usage' => $usage];
    }

    /**
     * Determina si la suscripción ha alcanzado el límite de usuarios.
     */
    public function hasReachedUserLimit(): bool
    {
        $data = $this->getUserLimitData();
        
        if ($data['limit'] === -1) {
            return false;
        }

        return $data['usage'] >= $data['limit'];
    }

    /**
     * Calcula y devuelve las advertencias de expiración del plan para la interfaz.
     */
    public function getWarningData(): ?array
    {
        $currentVersionAll = $this->versions()->latest('id')->first();

        if (!$currentVersionAll) {
            return null;
        }

        $endDate = \Carbon\Carbon::parse($currentVersionAll->end_date)->startOfDay();
        $today = now()->startOfDay();
        $daysRemaining = $today->diffInDays($endDate, false);
        $warningThreshold = 5;

        if ($daysRemaining < 0) {
            return [
                'daysRemaining' => $daysRemaining,
                'endDate' => $endDate->translatedFormat('d \d\e F \d\e\l Y'),
                'message' => "La suscripción expiró el " . $endDate->translatedFormat('d \d\e F'),
                'isExpired' => true
            ];
        } 
        
        if ($daysRemaining <= $warningThreshold) {
            $message = $daysRemaining == 0
                ? "La suscripción vence hoy"
                : "La suscripción vence en {$daysRemaining} " . ($daysRemaining === 1 ? 'día' : 'días');

            return [
                'daysRemaining' => $daysRemaining,
                'endDate' => $endDate->translatedFormat('d \d\e F \d\e\l Y'),
                'message' => $message,
                'isExpired' => false
            ];
        }

        return null;
    }

    /**
     * Devuelve el estado actual de la suscripción (usado en el controlador Show)
     */
    public function getStatusData(): array
    {
        $currentVersion = $this->versions->first(); // Asumiendo que la relación ya viene ordenada
        $isExpired = true;
        $daysUntilExpiry = 0;
        $currentBillingPeriod = BillingPeriod::ANNUALLY;

        if ($currentVersion) {
            $endDate = Carbon::parse($currentVersion->end_date);
            $isExpired = $endDate->isPast();
            $daysUntilExpiry = !$isExpired ? now()->startOfDay()->diffInDays($endDate->startOfDay(), false) : 0;

            $firstItem = $currentVersion->items->first();
            if ($firstItem && $firstItem->billing_period) {
                $currentBillingPeriod = $firstItem->billing_period;
            }
        }

        return [
            'isExpired' => $isExpired,
            'daysUntilExpiry' => $daysUntilExpiry,
            'currentBillingPeriod' => $currentBillingPeriod,
        ];
    }

    public function getPendingPayment()
    {
        return $this->payments()->where('status', SubscriptionPaymentStatus::PENDING)->latest('created_at')->first();
    }

    public function getLastRejectedPayment()
    {
        $lastPayment = $this->payments()->latest('created_at')->first();
        return ($lastPayment && $lastPayment->status === SubscriptionPaymentStatus::REJECTED) ? $lastPayment : null;
    }

    /**
     * Compara los items de todas las versiones para determinar si hubo upgrades/downgrades.
     */
    public function getVersionsWithComparison()
    {
        $versions = $this->versions;

        return $versions->map(function ($version, $index) use ($versions) {
            $previousVersion = $versions->get($index + 1);
            $previousItemsMap = $previousVersion ? $previousVersion->items->keyBy('item_key') : collect();

            $version->processed_items = $version->items->map(function ($newItem) use ($previousItemsMap) {
                $previousItem = $previousItemsMap->get($newItem->item_key);
                $previousQuantity = $previousItem ? $previousItem->quantity : 0;
                $newQuantity = $newItem->quantity;
                
                $status = 'unchanged'; 
                if (!$previousItem) $status = 'new';
                elseif ($newQuantity > $previousQuantity) $status = 'upgraded';
                elseif ($newQuantity < $previousQuantity) $status = 'downgraded';

                return [
                    'name' => $newItem->name,
                    'quantity' => $newQuantity,
                    'billing_period' => $newItem->billing_period,
                    'unit_price' => $newItem->unit_price,
                    'status' => $status,
                    'previous_quantity' => $previousQuantity,
                    'item_key' => $newItem->item_key,
                    'item_type' => $newItem->item_type,
                ];
            });

            return $version;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | RELACIONES Y MEDIA
    |--------------------------------------------------------------------------
    */

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('fiscal-documents')->singleFile();
    }

    public function printTemplates(): HasMany { return $this->hasMany(PrintTemplate::class); }
    public function bankAccounts(): HasMany { return $this->hasMany(BankAccount::class); }
    public function getRouteKeyName(): string { return 'slug'; }
    public function users(): HasManyThrough { return $this->hasManyThrough(User::class, Branch::class); }
    public function cashRegisters(): HasManyThrough { return $this->hasManyThrough(CashRegister::class, Branch::class); }
    public function products(): HasManyThrough { return $this->hasManyThrough(Product::class, Branch::class); }
    
    public function getProductsCountAttribute()
    {
        $simpleProducts = $this->products()->doesntHave('productAttributes')->count();
        $variantsCount = ProductAttribute::whereIn('product_id', $this->products()->pluck('products.id'))->count();
        return $simpleProducts + $variantsCount;
    }

    public function services(): HasManyThrough { return $this->hasManyThrough(Service::class, Branch::class); }
    
    public function getServicesCountAttribute()
    {
        $simpleServices = $this->services()->doesntHave('variants')->count();
        $variantsCount = ServiceVariant::whereIn('service_id', $this->services()->pluck('services.id'))->count();
        return $simpleServices + $variantsCount;
    }

    public function branches(): HasMany { return $this->hasMany(Branch::class, 'subscription_id'); }
    public function storeConfig(): \Illuminate\Database\Eloquent\Relations\HasOne { return $this->hasOne(StoreConfig::class, 'subscription_id'); }
    public function versions(): HasMany { return $this->hasMany(SubscriptionVersion::class, 'subscription_id'); }
    
    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(SubscriptionPayment::class, SubscriptionVersion::class, 'subscription_id', 'subscription_version_id');
    }

    public function expenses(): HasManyThrough { return $this->hasManyThrough(Expense::class, Branch::class); }
    public function settings(): MorphMany { return $this->morphMany(SettingValue::class, 'configurable'); }

    public function referralUsageAsReferred(): HasOne
    {
        return $this->hasOne(ReferralUsage::class, 'referred_subscription_id');
    }

    /**
     * Calcula dinámicamente el % total de descuento continuo que esta suscripción
     * recibe por todos sus referidos activos.
     */
    public function getReferrerActiveDiscountPct(): float
    {
        $totalPct = 0;

        foreach ($this->branches as $branch) {
            foreach ($branch->users as $user) {
                if ($user->referralUsagesAsReferrer()->exists()) {
                    $totalPct += (float) $user->referralUsagesAsReferrer()
                        ->whereHas('referredSubscription', fn($q) =>
                            $q->whereHas('versions', fn($v) => $v->where('end_date', '>=', now()->startOfDay()))
                        )
                        ->sum('referrer_ongoing_discount_pct');
                }
            }
        }

        return $totalPct;
    }
}