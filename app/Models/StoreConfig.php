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
        'mp_access_token',
        'mp_refresh_token',
        'mp_user_id',
        'mp_public_key',
        'mp_token_expires_at',
        'payment_mp_enabled',
        'payment_cash_enabled',
        'cash_instructions',
        'notify_email_enabled',
        'notification_emails',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'accepts_pickup' => 'boolean',
        'accepts_delivery' => 'boolean',
        'allow_out_of_stock_purchases' => 'boolean',
        'delivery_fee' => 'decimal:2',
        'free_shipping_minimum' => 'decimal:2',
        'payment_mp_enabled' => 'boolean',
        'payment_cash_enabled' => 'boolean',
        'notify_email_enabled' => 'boolean',
        'notification_emails' => 'array',
        'mp_token_expires_at' => 'datetime',
    ];

    protected $hidden = [
        'mp_access_token',
        'mp_refresh_token',
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
        $mediaUrl = $this->getFirstMediaUrl('store-logo');
        return $mediaUrl ?: $this->attributes['logo_url'] ?? null;
    }

    // ─── Mercado Pago encrypted accessors ───────────────────────────────

    public function getMpAccessTokenAttribute(): ?string
    {
        $value = $this->attributes['mp_access_token'] ?? null;
        return $value ? decrypt($value) : null;
    }

    public function setMpAccessTokenAttribute(?string $value): void
    {
        $this->attributes['mp_access_token'] = $value ? encrypt($value) : null;
    }

    public function getMpRefreshTokenAttribute(): ?string
    {
        $value = $this->attributes['mp_refresh_token'] ?? null;
        return $value ? decrypt($value) : null;
    }

    public function setMpRefreshTokenAttribute(?string $value): void
    {
        $this->attributes['mp_refresh_token'] = $value ? encrypt($value) : null;
    }

    public function isMpConnected(): bool
    {
        $isSandbox = $this->isMpTestMode();
        if ($isSandbox) {
            return !empty(config('services.mercadopago.test_access_token')) || !empty($this->mp_access_token);
        }
        return !empty($this->mp_access_token);
    }

    public function isMpTestMode(): bool
    {
        return config('services.mercadopago.env', 'sandbox') === 'sandbox';
    }

    public function mpAccountInfo(): ?array
    {
        $isSandbox = $this->isMpTestMode();

        // Real OAuth connection (production or local with real tokens)
        if ($this->isMpConnected() && !empty($this->mp_user_id)) {
            return [
                'user_id'   => $this->mp_user_id,
                'test_mode' => $isSandbox,
            ];
        }

        // Sandbox with test access token — show test account
        if ($isSandbox && config('services.mercadopago.test_access_token')) {
            return [
                'user_id'   => '3442108157',
                'name'      => 'Seller Test User',
                'country'   => 'México',
                'test_mode' => true,
            ];
        }

        return null;
    }

    // ────────────────────────────────────────────────────────────────────

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
