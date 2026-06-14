<?php

namespace App\Actions\Referral;

use App\Models\ReferralCode;
use App\Models\ReferralSettings;
use App\Models\Subscription;

class ApplyReferralDiscountAction
{
    /**
     * Aplica el descuento por código de referido al monto del primer pago.
     *
     * @return array{referral_code: ReferralCode, discount_pct: float, discount_amount: float, final_amount: float, settings: ReferralSettings}
     */
    public function execute(
        string $referralCode,
        Subscription $referredSubscription,
        float $originalAmount
    ): array {
        $referral = ReferralCode::where('code', $referralCode)
            ->where('is_active', true)
            ->firstOrFail();

        // No puede usar su propio código (misma suscripción)
        if ($referral->user->branch->subscription_id === $referredSubscription->id) {
            throw new \Exception('No puedes usar tu propio código de referido.');
        }

        // Solo aplica en el primer pago (máximo 1 versión existente)
        $isFirstPayment = $referredSubscription->versions()->count() <= 1;
        if (!$isFirstPayment) {
            throw new \Exception('El código de referido solo aplica en el primer pago.');
        }

        // Una suscripción solo puede ser referida una vez
        if ($referredSubscription->referralUsageAsReferred()->exists()) {
            throw new \Exception('Esta suscripción ya fue referida previamente.');
        }

        $settings = ReferralSettings::firstOrCreate([], [
            'referred_discount_pct' => 15.00,
            'referrer_reward_pct' => 50.00,
            'referrer_ongoing_discount_pct' => 10.00,
        ]);

        $discountPct = (float) $settings->referred_discount_pct;
        $discountAmount = round($originalAmount * ($discountPct / 100), 2);
        $finalAmount = $originalAmount - $discountAmount;

        return [
            'referral_code' => $referral,
            'discount_pct' => $discountPct,
            'discount_amount' => $discountAmount,
            'final_amount' => $finalAmount,
            'settings' => $settings,
        ];
    }
}
