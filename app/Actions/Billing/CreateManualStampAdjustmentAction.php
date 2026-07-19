<?php

namespace App\Actions\Billing;

use App\Enums\StampAdjustmentType;
use App\Enums\StampPaymentMethod;
use App\Enums\StampPurchaseStatus;
use App\Jobs\Billing\ApplyStampsToPacJob;
use App\Models\Billing\StampPurchase;

/**
 * CreateManualStampAdjustmentAction
 *
 * Creates a manual adjustment (add or remove stamps) by the superadmin.
 * This bypasses review — goes directly to approved and dispatches the PAC job.
 */
class CreateManualStampAdjustmentAction
{
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

        // Dispatch job to apply stamps to PAC (idempotent, queued)
        ApplyStampsToPacJob::dispatch($purchase->id);

        return $purchase;
    }
}
