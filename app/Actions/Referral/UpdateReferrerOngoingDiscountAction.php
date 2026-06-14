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

        $referrerUser = $usage->getReferrerUser();
        $referrerSubscription = $referrerUser->branch->subscription;

        // Recalcular si tiene descuento activo basado en los referidos aún vigentes
        $activePct = $referrerSubscription->getReferrerActiveDiscountPct();

        $referrerSubscription->update([
            'referrer_discount_active' => $activePct > 0,
        ]);
    }
}
