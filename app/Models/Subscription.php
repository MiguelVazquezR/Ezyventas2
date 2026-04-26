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
    public function versions(): HasMany { return $this->hasMany(SubscriptionVersion::class, 'subscription_id'); }
    
    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(SubscriptionPayment::class, SubscriptionVersion::class, 'subscription_id', 'subscription_version_id');
    }

    public function expenses(): HasManyThrough { return $this->hasManyThrough(Expense::class, Branch::class); }
    public function settings(): MorphMany { return $this->morphMany(SettingValue::class, 'configurable'); }
}