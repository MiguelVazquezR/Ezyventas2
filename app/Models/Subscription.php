<?php

namespace App\Models;

use App\Enums\PlanItemType;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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

    /**
     * Obtiene la versión de la suscripción que está actualmente activa.
     */
    public function currentVersion()
    {
        return $this->versions()
            ->where('start_date', '<=', now())
            ->where('end_date', '>=', now()->startOfDay())
            ->latest('id')
            ->first();
    }

    /**
     * Obtiene los nombres de los módulos disponibles en la versión activa de la suscripción.
     *
     * @return array
     */
    public function getAvailableModuleNames(): array
    {
        // Encuentra la versión de la suscripción que está actualmente activa.
        $currentVersion = $this->currentVersion();

        // Si no hay una versión activa, no hay módulos disponibles.
        if (!$currentVersion) {
            return [];
        }

        // Obtiene las claves de los items que son del tipo 'module'.
        $subscribedModuleKeys = $currentVersion->items()
            ->where('item_type', 'module')
            ->pluck('item_key')
            ->all();

        // Usa las claves para encontrar los nombres legibles de los módulos desde PlanItem.
        return PlanItem::whereIn('key', $subscribedModuleKeys)
            ->where('type', PlanItemType::MODULE)
            ->pluck('name')
            ->all();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('fiscal-documents')
            ->singleFile(); // Solo permite un archivo a la vez
    }

    /**
     * Obtiene todas las plantillas de impresión de la suscripción.
     */
    public function printTemplates(): HasMany
    {
        return $this->hasMany(PrintTemplate::class);
    }

    /**
     * Obtiene todas las cuentas bancarias de la suscripción.
     */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    /**
     * Get the route key for the model.
     *
     * @return string
     */
    public function getRouteKeyName(): string
    {
        return 'slug'; // 
    }

    /**
     * Get the users associated with the subscription.
     */
    public function users(): HasManyThrough
    {
        return $this->hasManyThrough(User::class, Branch::class);
    }

    public function cashRegisters(): HasManyThrough
    {
        return $this->hasManyThrough(CashRegister::class, Branch::class);
    }

    public function products(): HasManyThrough
    {
        return $this->hasManyThrough(Product::class, Branch::class);
    }

    /**
     * Get the branches for the subscription.
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class, 'subscription_id');
    }

    /**
     * Get the subscription versions for the subscription.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(SubscriptionVersion::class, 'subscription_id');
    }

    /**
     * Get all of the payments for the subscription through its versions.
     */
    public function payments(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        // This assumes a SubscriptionPayment belongs to a SubscriptionVersion
        return $this->hasManyThrough(
            SubscriptionPayment::class,
            SubscriptionVersion::class,
            'subscription_id', // Foreign key on SubscriptionVersion table...
            'subscription_version_id' // Foreign key on SubscriptionPayment table... 
        );
    }

    public function expenses(): HasManyThrough
    {
        return $this->hasManyThrough(Expense::class, Branch::class);
    }

    /**
     * AÑADIDO: Obtiene todas las configuraciones personalizadas de la suscripción.
     */
    public function settings(): MorphMany
    {
        return $this->morphMany(SettingValue::class, 'configurable');
    }
}
