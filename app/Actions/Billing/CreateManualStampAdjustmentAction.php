<?php

namespace App\Actions\Billing;

use App\Enums\StampAdjustmentType;
use App\Enums\StampPaymentMethod;
use App\Enums\StampPurchaseStatus;
use App\Models\Billing\StampPurchase;
use App\Services\Billing\StampPurchaseService;

/**
 * CreateManualStampAdjustmentAction
 *
 * Creates a manual adjustment (add or remove stamps) by the superadmin.
 * This bypasses review — goes directly to approved and applies stamps
 * to the PAC subaccount synchronously.
 */
class CreateManualStampAdjustmentAction
{
    public function __construct(
        private readonly StampPurchaseService $stampPurchaseService,
    ) {}

    /**
     * Execute the manual adjustment.
     *
     * @throws \RuntimeException When the PAC call fails.
     */
    public function execute(array $data): StampPurchase
    {
        $adjustmentType = $data['adjustment_type'] === 'remove'
            ? StampAdjustmentType::REMOVE
            : StampAdjustmentType::ADD;

        $purchase = StampPurchase::create([
            'fiscal_profile_id'    => $data['fiscal_profile_id'],
            'requested_by_user_id' => $data['requested_by_user_id'],
            'stamp_quantity'       => $data['stamp_quantity'],
            'unit_price'           => 0,
            'amount_total'         => 0,
            'payment_method'       => StampPaymentMethod::MANUAL_ADJUSTMENT,
            'status'               => StampPurchaseStatus::APPROVED,
            'admin_note'           => $data['admin_note'],
            'adjustment_type'      => $adjustmentType,
            'reviewed_by_user_id'  => $data['requested_by_user_id'],
            'reviewed_at'          => now(),
        ]);

        // Apply stamps to the PAC subaccount synchronously.
        // On success, status transitions to STAMPS_APPLIED.
        // On failure, marks as FAILED and rethrows.
        try {
            $this->stampPurchaseService->applyStampsToPac($purchase);
        } catch (\RuntimeException $e) {
            $purchase->update([
                'status' => StampPurchaseStatus::FAILED,
            ]);

            throw $e;
        }

        return $purchase;
    }
}
