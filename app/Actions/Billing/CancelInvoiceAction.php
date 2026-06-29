<?php

namespace App\Actions\Billing;

use App\Models\Billing\Invoice;
use App\Services\Billing\SWSapienService;

class CancelInvoiceAction
{
    public function __construct(
        private readonly SWSapienService $swService,
    ) {}

    /**
     * Cancel a CFDI invoice via SW Sapien.
     */
    public function execute(Invoice $invoice, string $cancellationReason, ?string $substitutionUuid = null): Invoice
    {
        if (! $invoice->isCertified()) {
            abort(422, 'Solo las facturas certificadas pueden ser canceladas.');
        }

        $invoice->load('fiscalProfile');

        if (! $invoice->fiscalProfile?->rfc) {
            abort(422, 'No se encontró el perfil fiscal asociado a esta factura.');
        }

        $this->swService->cancel(
            $invoice,
            $invoice->fiscalProfile->rfc,
            $cancellationReason,
            $substitutionUuid,
        );

        return $invoice->fresh();
    }
}
