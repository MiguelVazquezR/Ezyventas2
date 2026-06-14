<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Invoices\NovaBillingService;

class CancelInvoiceAction
{
    public function __construct(
        private readonly NovaBillingService $billingService,
    ) {}

    /**
     * Cancel a CFDI invoice.
     *
     * 1. Guard: only certified invoices can be canceled.
     * 2. Call the PAC service to request cancellation (NovaCFDI).
     * 3. Update the local invoice status to CANCELED with the reason and timestamp.
     */
    public function execute(Invoice $invoice, string $cancellationReason, ?string $substitutionUuid = null): Invoice
    {
        // Guard — only certified invoices can be canceled
        if (! $invoice->isCertified()) {
            abort(422, 'Solo las facturas certificadas pueden ser canceladas.');
        }

        // TODO: When NovaCFDI API key is available, call external PAC to cancel:
        // $response = Http::withToken($apiKey)
        //     ->post("https://api.novacfdi.com/v1/cfdi/{$invoice->uuid}/cancel", [
        //         'motivo'           => $cancellationReason,
        //         'folioSustitucion' => $substitutionUuid,
        //     ]);
        //
        // if ($response->failed()) {
        //     abort(422, 'El PAC rechazó la cancelación: ' . $response->json('message'));
        // }

        $invoice->update([
            'status'              => InvoiceStatus::CANCELED,
            'cancellation_reason' => $cancellationReason,
            'canceled_at'         => now(),
        ]);

        return $invoice->fresh();
    }
}
