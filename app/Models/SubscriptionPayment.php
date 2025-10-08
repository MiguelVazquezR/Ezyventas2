<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SubscriptionPayment extends Model
{
    use HasFactory;
    
    protected $table = 'subscription_payments';

    protected $fillable = ['subscription_version_id', 'amount', 'payment_method', 'invoiced', 'invoice_status'];

    protected $casts = [
        'amount' => 'decimal:2',
        'invoiced' => 'boolean',
        'invoice_status' => InvoiceStatus::class,
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