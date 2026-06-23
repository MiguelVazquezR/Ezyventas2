<?php

namespace App\Actions\Referral;

use App\Models\SubscriptionPayment;

class ProcessReferralOnPaymentApprovedAction
{
    /**
     * Al aprobarse el pago del referido, activa el descuento continuo
     * del referidor y dispara notificaciones.
     */
    public function execute(SubscriptionPayment $payment): void
    {
        $usage = $payment->referralUsage;

        if (!$usage) {
            return;
        }

        $referrerUser = $usage->getReferrerUser();
        $referrerSubscription = $referrerUser->branch->subscription;

        // Activar el descuento continuo (el % se calcula dinámicamente desde getReferrerActiveDiscountPct)
        $referrerSubscription->update([
            'referrer_discount_active' => true,
        ]);

        // TODO: Notificar al referidor (Notification::send)
    }
}
