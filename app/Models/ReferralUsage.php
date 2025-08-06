<?php

namespace App\Models;

use App\Enums\ReferralUsageStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralUsage extends Model
{
    use HasFactory;

    protected $table = 'referral_usages';

    protected $fillable = [
        'referral_code_id',
        'subscription_payment_id',
        'commission_earned',
        'status',
    ];

    protected $casts = [
        'commission_earned' => 'decimal:2',
        'status' => ReferralUsageStatus::class,
    ];

    public function referralCode(): BelongsTo
    {
        return $this->belongsTo(ReferralCode::class);
    }

    public function subscriptionPayment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class);
    }
}