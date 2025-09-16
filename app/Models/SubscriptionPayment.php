<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SubscriptionPayment extends Model
{
    use HasFactory;
    
    protected $table = 'subscription_payments';

    protected $fillable = ['subscription_version_id', 'amount', 'payment_method', 'invoiced'];

    protected $casts = [
        'amount' => 'decimal:2',
        'invoiced' => 'boolean',
    ];
    
    public function subscriptionVersion(): BelongsTo
    {
        return $this->belongsTo(SubscriptionVersion::class, 'subscription_version_id');
    }

    public function referralUsage(): HasOne
    {
        return $this->hasOne(ReferralUsage::class);
    }
}