<?php

namespace App\Actions\Billing;

use App\Enums\StampPurchaseStatus;
use App\Models\Billing\StampPurchase;
use App\Services\Billing\StampPurchaseService;

/**
 * ApproveStampPurchaseAction
 *
 * Approves a stamp purchase (bank transfer reviewed by superadmin,
 * or auto-approval from MercadoPago webhook) and applies stamps
 * to the PAC subaccount synchronously.
 *
 * The PAC call is synchronous so stamps are credited immediately
 * when the admin approves — no dependency on queue workers.
 */
class ApproveStampPurchaseAction
{
    public function __construct(
        private readonly StampPurchaseService $stampPurchaseService,
    ) {}

    /**
     * Approve the purchase and apply stamps to the PAC subaccount.
     *
     * @throws \RuntimeException When the PAC call fails.
     */
    public function execute(StampPurchase $purchase, int $reviewedByUserId): void
    {
        $purchase->update([
            'status'               => StampPurchaseStatus::APPROVED,
            'reviewed_by_user_id'  => $reviewedByUserId,
            'reviewed_at'          => now(),
        ]);

        // Apply stamps to the PAC subaccount synchronously.
        // On success, status transitions to STAMPS_APPLIED.
        // On failure, marks as FAILED and rethrows so the caller can
        // show a proper error to the user.
        try {
            $this->stampPurchaseService->applyStampsToPac($purchase);
        } catch (\RuntimeException $e) {
            $purchase->update([
                'status' => StampPurchaseStatus::FAILED,
            ]);

            throw $e;
        }
    }
}
