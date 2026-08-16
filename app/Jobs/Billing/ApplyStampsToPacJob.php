<?php

namespace App\Jobs\Billing;

use App\Models\Billing\StampPurchase;
use App\Services\Billing\StampPurchaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ApplyStampsToPacJob
 *
 * Dispatched after a stamp purchase is approved (MercadoPago webhook,
 * superadmin approval of bank transfer, or manual adjustment).
 *
 * Idempotent via the StampPurchaseService — checks stamps_applied
 * status before making the PAC call.
 */
class ApplyStampsToPacJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Delete the job if the stamp purchase no longer exists.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     */
    public int $backoff = 30;

    public function __construct(
        private readonly int $stampPurchaseId,
    ) {}

    public function handle(StampPurchaseService $stampPurchaseService): void
    {
        $purchase = StampPurchase::find($this->stampPurchaseId);

        if (! $purchase) {
            Log::warning('ApplyStampsToPacJob: stamp purchase not found', [
                'stamp_purchase_id' => $this->stampPurchaseId,
            ]);
            return;
        }

        // Idempotency: if already applied, skip.
        if ($purchase->isStampsApplied()) {
            Log::info('ApplyStampsToPacJob: stamps already applied — skipping', [
                'stamp_purchase_id' => $this->stampPurchaseId,
            ]);
            return;
        }

        $stampPurchaseService->applyStampsToPac($purchase);
    }

    /**
     * Unique identifier for deduplication.
     */
    public function uniqueId(): string
    {
        return 'stamp-purchase-' . $this->stampPurchaseId;
    }
}
