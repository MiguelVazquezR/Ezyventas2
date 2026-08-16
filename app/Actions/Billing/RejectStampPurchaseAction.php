<?php

namespace App\Actions\Billing;

use App\Enums\StampPurchaseStatus;
use App\Models\Billing\StampMovement;
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

        // Mark the pending movement as rejected — stamps never counted toward balance
        $movement = StampMovement::where('reference_type', StampPurchase::class)
            ->where('reference_id', $purchase->id)
            ->first();

        if ($movement) {
            $metadata = $movement->metadata ?? [];
            $metadata['status'] = 'rejected';
            $metadata['rejection_reason'] = $rejectionReason;

            $movement->update([
                'description' => 'Compra de timbres por transferencia — rechazada',
                'metadata'    => $metadata,
            ]);
        }
    }
}
