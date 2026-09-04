<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralUsage extends Model
{
    use HasFactory;

    /**
     * Referral captured at sign-up while the referred subscription is still on
     * its free trial (no payment yet).
     */
    public const STATUS_TRIAL = 'trial';

    /**
     * The free trial ended and the referred subscription never paid.
     */
    public const STATUS_EXPIRED = 'expired';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'referral_code_id',
        'referred_subscription_id',
        'subscription_payment_id',
        'reward_status',
        'referred_discount_pct',
        'referrer_reward_pct',
        'referrer_ongoing_discount_pct',
        'monthly_base_amount',
        'reward_amount',
        'reward_paid_at',
        'seen_at',
    ];

    protected $casts = [
        'reward_paid_at' => 'datetime',
        'seen_at' => 'datetime',
    ];

    public function referralCode(): BelongsTo
    {
        return $this->belongsTo(ReferralCode::class);
    }

    public function referredSubscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'referred_subscription_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class, 'subscription_payment_id');
    }

    public function getReferrerUser(): User
    {
        return $this->referralCode->user;
    }
}
