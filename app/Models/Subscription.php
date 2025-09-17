<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasFactory;

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
        'address' => 'array', // [cite: 414]
        'status' => SubscriptionStatus::class, // 
    ];

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
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'subscription_id');
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
}
