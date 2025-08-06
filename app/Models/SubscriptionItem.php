<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionItem extends Model
{
    use HasFactory;

    protected $table = 'subscription_items';

    protected $fillable = [
        'suscription_version_id',
        'item_key',
        'item_type',
        'name',
        'quantity',
        'unit_price',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
    ];

    public function subscriptionVersion(): BelongsTo
    {
        return $this->belongsTo(SubscriptionVersion::class, 'suscription_version_id');
    }
}