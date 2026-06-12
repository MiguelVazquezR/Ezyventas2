<?php

namespace App\Actions\Referral;

use App\Models\Subscription;

class UpdateReferrerOngoingDiscountAction
{
    /**
     * Activa o desactiva el descuento continuo del referidor
     * según el estado de la suscripción del referido.
     */
    public function execute(Subscription $referredSubscription): void
    {
        $usage = $referredSubscription->referralUsageAsReferred;

        if (!$usage) {
            return;
        }

        $referrerSubscription = $usage->getReferrerSubscription();
        $isReferredActive = $referredSubscription->computed_status === 'activo';

        $referrerSubscription->update([
            'referrer_discount_active' => $isReferredActive,
        ]);
    }
}
