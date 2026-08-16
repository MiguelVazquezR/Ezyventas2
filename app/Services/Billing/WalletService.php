<?php

namespace App\Services\Billing;

use App\Models\Billing\StampMovement;
use App\Models\Billing\StampReservation;

/**
 * WalletService
 *
 * Local stamp wallet for "normal" PAC accounts (shared pool).
 *
 * For normal accounts there is no per-client balance in the PAC, so
 * stamp_movements becomes the source of truth for each fiscal_profile's
 * available balance:
 *
 *   available = (sum of confirmed entries - exits) - held/ambiguous reservations
 *
 * Held/ambiguous reservations count as "possibly already spent" so two
 * concurrent stampings cannot consume the same stamp of the shared pool.
 *
 * This service is NOT used for subaccount-type accounts — their balance is
 * always queried live from the PAC.
 */
class WalletService
{
    /**
     * Available stamp balance for a fiscal profile (normal accounts only).
     */
    public function availableBalance(int $fiscalProfileId): int
    {
        // Only count movements tied to a stamp purchase once that purchase has
        // actually been applied (stamps_applied). Bank-transfer purchases create
        // a "pending" movement at order time — those must NOT count toward the
        // available balance until the admin approves and stamps are applied.
        $confirmedNet = StampMovement::where('fiscal_profile_id', $fiscalProfileId)
            ->walletConfirmed()
            ->selectRaw("SUM(CASE WHEN type = 'entry' THEN quantity ELSE -quantity END) as net")
            ->value('net') ?? 0;

        $held = StampReservation::where('fiscal_profile_id', $fiscalProfileId)
            ->whereIn('status', ['held', 'ambiguous']) // lo ambiguo también cuenta como "posiblemente ya gastado"
            ->sum('quantity');

        return (int) $confirmedNet - (int) $held;
    }

    /**
     * Total welcome (gift) stamps granted to a profile.
     *
     * Gift stamps exist only in the local wallet — not in the real PAC
     * balance — so reconciliation subtracts them from the expected value.
     */
    public function welcomeStampsGranted(int $fiscalProfileId): int
    {
        return (int) StampMovement::where('fiscal_profile_id', $fiscalProfileId)
            ->where('metadata->source', 'gift')
            ->where('type', 'entry')
            ->sum('quantity');
    }
}
