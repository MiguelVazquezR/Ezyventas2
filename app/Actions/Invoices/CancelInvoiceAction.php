<?php

namespace App\Actions\Invoices;

use App\Models\BillingSetting;
use App\Models\Invoice;
use App\Services\Invoices\SWSapienService;

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

        $billingSetting = BillingSetting::where('branch_id', $invoice->branch_id)->first();

        if (! $billingSetting?->emitter_rfc) {
            abort(422, 'No se encontró la configuración fiscal de la sucursal.');
        }

        $this->swService->cancel($invoice, $billingSetting->emitter_rfc, $cancellationReason, $substitutionUuid);

        return $invoice->fresh();
    }
}
