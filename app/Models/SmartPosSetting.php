<?php

namespace App\Models;

use App\Traits\HasSubscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Modo Caja Inteligente" preferences of one subscription (tenant).
 *
 * Business level: one row per subscription. A missing row must always be read as
 * "every feature disabled", so consumers go through SmartPosSettingsService
 * instead of querying this model directly.
 */
class SmartPosSetting extends Model
{
    use HasSubscription;

    protected $table = 'smartpos_settings';

    protected $fillable = [
        'subscription_id',
        'smart_checkout_enabled',
        'scale_enabled',
        'scale_settings',
        'smart_checkout_settings',
        'retention_days',
        'save_crops',
    ];

    protected function casts(): array
    {
        return [
            'smart_checkout_enabled' => 'boolean',
            'scale_enabled' => 'boolean',
            'scale_settings' => 'array',
            'smart_checkout_settings' => 'array',
            'retention_days' => 'integer',
            'save_crops' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | RELACIONES
    |--------------------------------------------------------------------------
    */

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES / HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Scopes the query to the row of a given subscription.
     */
    public function scopeForSubscription(Builder $query, int $subscriptionId): Builder
    {
        return $query->where('subscription_id', $subscriptionId);
    }

    /**
     * Scale (bulk weighing) enabled for this business.
     */
    public function hasScaleEnabled(): bool
    {
        return (bool) $this->scale_enabled;
    }

    /**
     * Smart checkout enabled for this business (Phase 2).
     */
    public function hasSmartCheckoutEnabled(): bool
    {
        return (bool) $this->smart_checkout_enabled;
    }
}
