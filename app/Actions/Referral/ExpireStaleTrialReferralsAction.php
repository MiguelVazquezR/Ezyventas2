<?php

namespace App\Actions\Referral;

use App\Enums\SubscriptionPaymentStatus;
use App\Models\ReferralUsage;

class ExpireStaleTrialReferralsAction
{
    /**
     * Marks as 'expired' the referrals captured during sign-up ('trial') whose
     * free trial ended without the referred subscription making its first
     * payment. The referral is not lost forever: if that subscription pays
     * later, the payment flow converts it back to 'pending'.
     *
     * @param  array<int, int>|null  $referralCodeIds  restrict to specific referrer codes
     */
    public function execute(?array $referralCodeIds = null): int
    {
        $query = ReferralUsage::query()
            ->where('reward_status', ReferralUsage::STATUS_TRIAL)
            ->whereNull('subscription_payment_id')
            ->whereHas('referredSubscription', function ($subscription) {
                $subscription
                    // The free trial (its only/active version) already ended...
                    ->whereDoesntHave('versions', fn ($version) =>
                        $version->where('end_date', '>=', now()->startOfDay())
                    )
                    // ...and there is no payment pending or approved yet.
                    ->whereDoesntHave('payments', fn ($payment) =>
                        $payment->whereIn('status', [
                            SubscriptionPaymentStatus::PENDING,
                            SubscriptionPaymentStatus::APPROVED,
                        ])
                    );
            });

        if ($referralCodeIds !== null) {
            $query->whereIn('referral_code_id', $referralCodeIds);
        }

        return $query->update(['reward_status' => ReferralUsage::STATUS_EXPIRED]);
    }
}
