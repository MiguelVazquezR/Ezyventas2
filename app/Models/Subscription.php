<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
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
    ];

    protected $casts = [
        'address' => 'array',
        'status' => SubscriptionStatus::class,
    ];

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

    /**
     * AÑADIDO: Obtiene todas las configuraciones personalizadas de la suscripción.
     */
    public function settings(): MorphMany
    {
        return $this->morphMany(SettingValue::class, 'configurable');
    }
}