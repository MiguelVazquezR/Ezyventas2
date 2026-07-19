<?php

namespace App\Actions\Billing;

use App\Enums\StampPurchaseStatus;
use App\Jobs\Billing\ApplyStampsToPacJob;
use App\Models\Billing\StampPurchase;

/**
 * ApproveStampPurchaseAction
 *
 * Approves a stamp purchase (bank transfer reviewed by superadmin,
 * or auto-approval from MercadoPago webhook) and dispatches the
 * PAC application job.
 */
class ApproveStampPurchaseAction
{
    public function execute(StampPurchase $purchase, int $reviewedByUserId): void
    {
        $purchase->update([
            'status'               => StampPurchaseStatus::APPROVED,
            'reviewed_by_user_id'  => $reviewedByUserId,
            'reviewed_at'          => now(),
        ]);

        // Dispatch job to apply stamps to PAC (idempotent, queued)
        ApplyStampsToPacJob::dispatch($purchase->id);
    }
}
