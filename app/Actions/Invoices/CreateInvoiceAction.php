<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoices\NovaBillingService;
use Illuminate\Support\Facades\DB;

class CreateInvoiceAction
{
    public function __construct(
        private readonly NovaBillingService $billingService,
    ) {}

    /**
     * Orchestrate the creation of a new CFDI invoice.
     *
     * 1. Persist the invoice and its items.
     * 2. Prepare the NovaCFDI payload for PAC stamping (when the API key is available).
     * 3. Return the invoice model.
     */
    public function execute(array $data, User $user): Invoice
    {
        return DB::transaction(function () use ($data, $user) {
            // 1. Persist invoice + items through the billing service
            $invoice = $this->billingService->createInvoice(
                data: $data,
                branchId: $user->branch_id,
            );

            // 2. Prepare NovaCFDI payload (ready for when the API key is configured)
            $novaPayload = $this->billingService->prepareNovaPayload($invoice);

            // TODO: When NovaCFDI API key is available, call external PAC here:
            // $response = Http::withToken($apiKey)
            //     ->post('https://api.novacfdi.com/v1/cfdi', $novaPayload);
            //
            // Then update invoice with uuid, xml_url, pdf_url, status = CERTIFIED, issued_at = now()

            return $invoice->fresh('items');
        });
    }
}
