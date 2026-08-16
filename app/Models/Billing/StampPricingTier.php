<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * StampPricingTier
 *
 * Volume-based pricing tiers for stamp purchases.
 * Each tier defines a unit price for a quantity range.
 * The applicable tier is determined by the total quantity
 * requested, not accumulated progressively.
 */
class StampPricingTier extends Model
{
    use HasFactory;

    protected $table = 'stamp_pricing_tiers';

    protected $fillable = [
        'min_quantity',
        'max_quantity',
        'unit_price',
        'label',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'min_quantity' => 'integer',
            'max_quantity' => 'integer',
            'unit_price'   => 'decimal:4',
            'is_active'    => 'boolean',
            'sort_order'   => 'integer',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('min_quantity');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Find the applicable tier for a given quantity.
     * Returns null if no tier matches.
     */
    public static function findForQuantity(int $quantity): ?self
    {
        return static::active()->ordered()
            ->where('min_quantity', '<=', $quantity)
            ->where(function (Builder $query) use ($quantity) {
                $query->whereNull('max_quantity')
                      ->orWhere('max_quantity', '>=', $quantity);
            })
            ->first();
    }
}
