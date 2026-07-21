<?php

namespace App\Services\Billing;

use App\Models\Billing\FiscalProfile;
use App\Models\Billing\StampPricingTier;
use App\Models\Billing\StampPurchase;
use App\Services\SW\SWUserService;
use Illuminate\Support\Facades\Log;

/**
 * StampPurchaseService
 *
 * Reusable business logic for stamp purchases:
 *  - Price calculation via volume tiers
 *  - Purchase record creation
 *  - PAC stamp application (abono / retiro)
 */
class StampPurchaseService
{
    public function __construct(
        private readonly SWUserService $swUserService,
    ) {}

    /**
     * Calculate the applicable unit price and total for a given quantity.
     *
     * @return array{
     *     unit_price: float,
     *     amount_total: float,
     *     pricing_tier_id: int|null,
     *     pricing_tier_label: string|null,
     *     base_unit_price: float,
     *     savings_amount: float,
     *     savings_percentage: int,
     * }
     */
    public function calculatePrice(int $quantity): array
    {
        $tier = StampPricingTier::findForQuantity($quantity);
        $baseTier = StampPricingTier::findForQuantity(1);

        $baseUnitPrice = $baseTier ? (float) $baseTier->unit_price : 0.0;

        if (! $tier) {
            return [
                'unit_price'         => 0.0,
                'amount_total'       => 0.0,
                'pricing_tier_id'    => null,
                'pricing_tier_label' => null,
                'base_unit_price'    => $baseUnitPrice,
                'savings_amount'     => 0.0,
                'savings_percentage' => 0,
            ];
        }

        $unitPrice   = (float) $tier->unit_price;
        $amountTotal = round($quantity * $unitPrice, 2);
        $savingsPerUnit = $baseUnitPrice - $unitPrice;
        $savingsTotal = round($savingsPerUnit * $quantity, 2);
        $savingsPct = $baseUnitPrice > 0
            ? (int) round(($savingsPerUnit / $baseUnitPrice) * 100)
            : 0;

        return [
            'unit_price'         => $unitPrice,
            'amount_total'       => $amountTotal,
            'pricing_tier_id'    => $tier->id,
            'pricing_tier_label' => $tier->label,
            'base_unit_price'    => $baseUnitPrice,
            'savings_amount'     => $savingsTotal,
            'savings_percentage' => $savingsPct,
        ];
    }

    /**
     * Create a stamp purchase record.
     */
    public function createPurchase(array $data): StampPurchase
    {
        return StampPurchase::create($data);
    }

    /**
     * Apply stamps to the PAC subaccount for a given purchase.
     *
     * For manual_adjustment with adjustment_type = 'remove', calls removeStampsFromSubaccount.
     * For all other cases (purchases, manual add), calls addStampsToSubaccount.
     *
     * This method is idempotent: if the purchase is already stamps_applied, it returns early.
     *
     * @throws \RuntimeException When the PAC call fails.
     */
    public function applyStampsToPac(StampPurchase $purchase): void
    {
        // Idempotency guard: never apply stamps twice
        if ($purchase->isStampsApplied()) {
            Log::warning('StampPurchase already applied — skipping duplicate PAC call', [
                'stamp_purchase_id' => $purchase->id,
            ]);
            return;
        }

        $fiscalProfile = $purchase->fiscalProfile;

        if (! $fiscalProfile || ! $fiscalProfile->sw_user_id) {
            throw new \RuntimeException(
                "El perfil fiscal #{$purchase->fiscal_profile_id} no tiene una subcuenta PAC vinculada."
            );
        }

        $comment = $this->buildPacComment($purchase);

        if ($purchase->isManualAdjustment() && $purchase->adjustment_type?->value === 'remove') {
            $response = $this->swUserService->removeStampsFromSubaccount(
                $fiscalProfile->sw_user_id,
                $purchase->stamp_quantity,
                $comment,
            );
        } else {
            $response = $this->swUserService->addStampsToSubaccount(
                $fiscalProfile->sw_user_id,
                $purchase->stamp_quantity,
                $comment,
            );
        }

        $purchase->update([
            'pac_stamps_response_raw' => $response,
            'stamps_applied_at'       => now(),
            'status'                  => \App\Enums\StampPurchaseStatus::STAMPS_APPLIED,
        ]);

        Log::info('Stamps applied to PAC', [
            'stamp_purchase_id'  => $purchase->id,
            'fiscal_profile_id'  => $fiscalProfile->id,
            'quantity'           => $purchase->stamp_quantity,
            'adjustment_type'    => $purchase->adjustment_type?->value,
        ]);
    }

    /**
     * Build a descriptive comment for the PAC audit trail.
     */
    private function buildPacComment(StampPurchase $purchase): string
    {
        if ($purchase->isManualAdjustment()) {
            $adminName = $purchase->reviewedBy?->name ?? 'Superadmin';
            $note      = $purchase->admin_note ? ": {$purchase->admin_note}" : '';
            $action    = $purchase->adjustment_type?->value === 'remove' ? 'Retiro' : 'Ajuste';

            return "{$action} manual por {$adminName}{$note}";
        }

        if ($purchase->isMercadoPago()) {
            return "Compra #{$purchase->id} vía Mercado Pago";
        }

        return "Transferencia aprobada #{$purchase->id}";
    }

    /**
     * Verify the master account has enough stamps to fulfill a purchase or adjustment.
     *
     * For "add" adjustments and purchases: checks that stampsBalance >= quantity.
     * For "remove" adjustments: does not check (you're returning stamps to the master).
     *
     * @param int  $quantity       Number of stamps being purchased/assigned.
     * @param bool $isRemoval      True if this is a removal (returns stamps to master).
     *
     * @return array{sufficient: bool, stampsBalance: int, stampsAssigned: int}
     */
    public function checkMasterBalance(int $quantity, bool $isRemoval = false): array
    {
        // Removing stamps (returning to master) — no balance check needed.
        if ($isRemoval) {
            return ['sufficient' => true, 'stampsBalance' => 0, 'stampsAssigned' => 0];
        }

        try {
            $balance = $this->swUserService->getMasterAccountBalance();
        } catch (\RuntimeException $e) {
            Log::warning('Master account balance check failed — allowing purchase to proceed', [
                'error' => $e->getMessage(),
            ]);

            // If we can't check, allow the purchase to proceed rather than
            // blocking legitimate transactions due to a transient PAC error.
            return ['sufficient' => true, 'stampsBalance' => 0, 'stampsAssigned' => 0];
        }

        $stampsBalance  = (int) ($balance['stampsBalance'] ?? 0);
        $stampsAssigned = (int) ($balance['stampsAssigned'] ?? 0);

        return [
            'sufficient'     => $stampsBalance >= $quantity,
            'stampsBalance'  => $stampsBalance,
            'stampsAssigned' => $stampsAssigned,
        ];
    }
}
