<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Billing\Invoice;
use App\Models\Billing\StampReservation;
use App\Services\Billing\SWSapienService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ConfirmManualReviewAction
 *
 * Admin confirms that a manual_review reservation was actually stamped
 * (verified externally, e.g. in the SW Sapien / Conectia portal).
 *
 * Resolution order:
 *  1. If the reservation's last_pac_response already carries the complete data
 *     (e.g. a "307" snapshot with cfdi), recover automatically.
 *  2. Otherwise persist whatever the admin captured manually (uuid and/or XML).
 *  3. Either way, the reservation becomes 'confirmed' and the invoice CERTIFIED.
 */
class ConfirmManualReviewAction
{
    public function __construct(
        private readonly SWSapienService $swService,
    ) {}

    /**
     * Execute the manual confirmation.
     *
     * @param  StampReservation $reservation
     * @param  string|null      $uuid    UUID captured by the admin (optional).
     * @param  string|null      $cfdiXml CFDI XML captured by the admin (optional).
     * @return void
     */
    public function execute(StampReservation $reservation, ?string $uuid = null, ?string $cfdiXml = null): void
    {
        $invoice = $reservation->reference;

        if (! $invoice instanceof Invoice) {
            throw new \RuntimeException('La factura asociada a esta reserva ya no existe.');
        }

        // Build the best possible response data.
        $data = $reservation->last_pac_response['data'] ?? [];

        if ($uuid) {
            $data['uuid'] = $uuid;
        }

        if ($cfdiXml) {
            $data['cfdi'] = $cfdiXml;
        }

        DB::transaction(function () use ($reservation, $invoice, $data) {
            $hasFullData = ! empty($data['cfdi']) && ! empty($data['uuid']);

            if ($hasFullData) {
                // Persist XML + sellos + CERTIFIED.
                $this->swService->persistStampedInvoice($invoice, $data);
            } else {
                $update = [
                    'status'                 => InvoiceStatus::CERTIFIED,
                    'requires_manual_review' => false,
                    'issued_at'              => now(),
                ];

                if (! empty($data['uuid'])) {
                    $update['uuid'] = $data['uuid'];
                }

                $invoice->update($update);
            }

            $reservation->update([
                'status'            => 'confirmed',
                'confirmed_at'      => now(),
                'last_pac_response' => $data ?: null,
            ]);
        });

        Log::info('Manual review reservation confirmed by admin', [
            'stamp_reservation_id' => $reservation->id,
            'invoice_id'           => $invoice->id,
            'has_full_data'        => isset($data['cfdi']) && isset($data['uuid']),
        ]);
    }
}
