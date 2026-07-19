<?php

namespace App\Actions\Billing;

use App\Enums\StampPurchaseStatus;
use App\Models\Billing\StampPurchase;

/**
 * RejectStampPurchaseAction
 *
 * Rejects a bank transfer stamp purchase.
 * No PAC call is made.
 */
class RejectStampPurchaseAction
{
    public function execute(StampPurchase $purchase, int $reviewedByUserId, string $rejectionReason): void
    {
        $purchase->update([
            'status'               => StampPurchaseStatus::REJECTED,
            'reviewed_by_user_id'  => $reviewedByUserId,
            'reviewed_at'          => now(),
            'rejection_reason'     => $rejectionReason,
        ]);
    }
}
