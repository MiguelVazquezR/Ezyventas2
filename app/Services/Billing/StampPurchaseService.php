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
     * @return array{unit_price: float, amount_total: float, pricing_tier_id: int|null}
     */
    public function calculatePrice(int $quantity): array
    {
        $tier = StampPricingTier::findForQuantity($quantity);

        if (! $tier) {
            // No tier configured — fallback to 0. This should not happen
            // once the superadmin configures tiers, but it's a safe default.
            return [
                'unit_price'       => 0.0,
                'amount_total'     => 0.0,
                'pricing_tier_id'  => null,
            ];
        }

        $unitPrice   = (float) $tier->unit_price;
        $amountTotal = round($quantity * $unitPrice, 2);

        return [
            'unit_price'       => $unitPrice,
            'amount_total'     => $amountTotal,
            'pricing_tier_id'  => $tier->id,
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
}
