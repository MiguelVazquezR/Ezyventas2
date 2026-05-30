<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class StoreConfig extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'subscription_id',
        'slug',
        'is_active',
        'store_name',
        'description',
        'logo_url',
        'primary_color',
        'secondary_color',
        'welcome_message',
        'accepts_pickup',
        'accepts_delivery',
        'delivery_fee',
        'preparation_time_minutes',
        'delivery_policy',
        'footer_note',
        'custom_domain',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'accepts_pickup' => 'boolean',
        'accepts_delivery' => 'boolean',
        'delivery_fee' => 'decimal:2',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('store-logo')->singleFile();
    }

    public function getLogoUrlAttribute(): ?string
    {
        // Override the DB column with the media library URL if available
        $mediaUrl = $this->getFirstMediaUrl('store-logo');
        return $mediaUrl ?: $this->attributes['logo_url'] ?? null;
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function products(): HasManyThrough
    {
        return $this->hasManyThrough(
            Product::class,
            Branch::class,
            'subscription_id', // Foreign key on Branch table
            'branch_id',       // Foreign key on Product table
            'subscription_id', // Local key on StoreConfig table
            'id'               // Local key on Branch table
        );
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'subscription_id', 'subscription_id');
    }
}
