<?php

namespace App\Models;

use App\Enums\BillingPeriod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionItem extends Model
{
    use HasFactory;

    protected $table = 'subscription_items';

    protected $fillable = [
        'subscription_version_id',
        'item_key',
        'item_type',
        'name',
        'quantity',
        'unit_price',
        'billing_period',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'billing_period' => BillingPeriod::class,
    ];

    public function subscriptionVersion(): BelongsTo
    {
        return $this->belongsTo(SubscriptionVersion::class, 'subscription_version_id');
    }
}