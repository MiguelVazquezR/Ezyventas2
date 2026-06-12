<?php

namespace App\Actions\Referral;

use App\Models\ReferralCode;
use App\Models\Subscription;
use Illuminate\Support\Str;

class GenerateReferralCodeAction
{
    public function execute(Subscription $subscription): ReferralCode
    {
        if ($subscription->referralCode) {
            return $subscription->referralCode;
        }

        do {
            $code = 'EZY-' . strtoupper(Str::random(6));
        } while (ReferralCode::where('code', $code)->exists());

        return ReferralCode::create([
            'subscription_id' => $subscription->id,
            'code' => $code,
            'is_active' => true,
        ]);
    }
}
