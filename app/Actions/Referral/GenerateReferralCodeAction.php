<?php

namespace App\Actions\Referral;

use App\Models\ReferralCode;
use App\Models\User;
use Illuminate\Support\Str;

class GenerateReferralCodeAction
{
    public function execute(User $user): ReferralCode
    {
        if ($user->referralCode) {
            return $user->referralCode;
        }

        do {
            $code = 'EZY-' . strtoupper(Str::random(6));
        } while (ReferralCode::where('code', $code)->exists());

        return ReferralCode::create([
            'user_id' => $user->id,
            'code' => $code,
            'is_active' => true,
        ]);
    }
}
