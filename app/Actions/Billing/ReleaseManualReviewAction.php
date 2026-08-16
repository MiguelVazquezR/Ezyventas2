<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Billing\Invoice;
use App\Models\Billing\StampReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ReleaseManualReviewAction
 *
 * Admin confirms that a manual_review reservation was NEVER stamped (verified
 * externally). The reservation is released and the invoice returns to DRAFT so
 * the user can retry — with a brand-new customid (it is a different attempt).
 */
class ReleaseManualReviewAction
{
    /**
     * Execute the manual release.
     *
     * @return void
     */
    public function execute(StampReservation $reservation): void
    {
        $invoice = $reservation->reference;

        if (! $invoice instanceof Invoice) {
            throw new \RuntimeException('La factura asociada a esta reserva ya no existe.');
        }

        DB::transaction(function () use ($reservation, $invoice) {
            $reservation->update([
                'status'            => 'released',
                'released_at'       => now(),
                'last_pac_response' => array_merge(
                    (array) ($reservation->last_pac_response ?? []),
                    ['released_by_admin' => true, 'released_at' => now()->toISOString()],
                ),
            ]);

            $invoice->update([
                'status'                 => InvoiceStatus::DRAFT,
                'requires_manual_review' => false,
            ]);
        });

        Log::info('Manual review reservation released by admin (never stamped)', [
            'stamp_reservation_id' => $reservation->id,
            'invoice_id'           => $invoice->id,
        ]);
    }
}
