<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Billing\Invoice;
use App\Models\Transaction;
use App\Services\Billing\SWSapienService;
use Illuminate\Support\Facades\Log;

class CancelInvoiceAction
{
    public function __construct(
        private readonly SWSapienService $swService,
    ) {}

    /**
     * Cancel a CFDI invoice via SW Sapien.
     *
     * Returns a status string:
     *  - 'canceled'         → immediate cancelation (Cancelable sin aceptación)
     *  - 'pending_acceptance' → receiver must accept (Cancelable con aceptación)
     *
     * @return array{status: string, message: string}
     */
    public function execute(Invoice $invoice, string $cancellationReason, ?string $substitutionUuid = null): array
    {
        if (! $invoice->isCertified()) {
            abort(422, 'Solo las facturas certificadas pueden ser canceladas.');
        }

        $invoice->load('fiscalProfile');

        if (! $invoice->fiscalProfile?->rfc) {
            abort(422, 'No se encontró el perfil fiscal asociado a esta factura.');
        }

        $responseData = $this->swService->cancel(
            $invoice,
            $invoice->fiscalProfile->rfc,
            $cancellationReason,
            $substitutionUuid,
        );

        $isCancelable      = $responseData['isCancelable'] ?? '';
        $statusCancelation = $responseData['statusCancelation'] ?? null;

        if ($isCancelable === 'Cancelable sin aceptación') {
            // Immediate cancelation — the CFDI is now canceled
            $invoice->update([
                'status'              => InvoiceStatus::CANCELED,
                'cancellation_reason' => $cancellationReason,
                'canceled_at'         => now(),
                'cancelation_status'  => $statusCancelation,
            ]);

            // Release the linked POS sale so it can be invoiced again.
            if ($invoice->transaction_id) {
                Transaction::where('id', $invoice->transaction_id)->update(['invoiced' => false]);
            }

            Log::info('CFDI canceled immediately', [
                'invoice_id' => $invoice->id,
                'uuid'       => $invoice->uuid,
            ]);

            return [
                'status'  => 'canceled',
                'message' => 'Factura cancelada correctamente.',
            ];
        }

        // Cancelable con aceptación — the receiver must approve
        $invoice->update([
            'status'                          => InvoiceStatus::CANCELATION_PENDING,
            'cancellation_reason'             => $cancellationReason,
            'cancelation_requires_acceptance' => true,
            'cancelation_status'              => $statusCancelation,
            'cancelation_requested_at'        => now(),
        ]);

        Log::info('CFDI cancelation pending receiver acceptance', [
            'invoice_id' => $invoice->id,
            'uuid'       => $invoice->uuid,
            'status'     => $statusCancelation,
        ]);

        return [
            'status'  => 'pending_acceptance',
            'message' => 'Se envió la solicitud de cancelación. Tu cliente (RFC receptor) debe aceptarla o rechazarla ante el SAT. Te avisaremos cuando se resuelva. Mientras tanto, esta factura sigue vigente para efectos fiscales.',
        ];
    }
}
