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
        'tagline',
        'logo_url',
        'primary_color',
        'secondary_color',
        'theme_mode',
        'welcome_message',
        'accepts_pickup',
        'accepts_delivery',
        'allow_out_of_stock_purchases',
        'out_of_stock_extra_minutes',
        'whatsapp_number',
        'delivery_fee',
        'free_shipping_minimum',
        'preparation_time_minutes',
        'delivery_policy',
        'terms_policy',
        'footer_note',
        'custom_domain',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'accepts_pickup' => 'boolean',
        'accepts_delivery' => 'boolean',
        'allow_out_of_stock_purchases' => 'boolean',
        'delivery_fee' => 'decimal:2',
        'free_shipping_minimum' => 'decimal:2',
    ];

    protected $appends = ['banners'];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('store-logo')->singleFile();
        $this->addMediaCollection('store-banners');
    }

    public function getBannersAttribute(): array
    {
        return $this->getMedia('store-banners')->map(fn($media) => [
            'id' => $media->id,
            'url' => $media->getFullUrl(),
        ])->toArray();
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
