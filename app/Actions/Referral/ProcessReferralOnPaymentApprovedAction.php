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

        $referrerSubscription = $usage->getReferrerSubscription();

        // Activar el descuento continuo en la suscripción del referidor
        $referrerSubscription->update([
            'referrer_discount_active' => true,
            'referrer_ongoing_discount_pct' => $usage->referrer_ongoing_discount_pct,
        ]);

        // TODO: Notificar al referidor (Notification::send)
    }
}
