<?php

namespace App\Actions\Invoices;

use App\Models\Invoices\Invoice;
use App\Models\User;
use App\Services\Invoices\SWSapienService;
use Illuminate\Support\Facades\DB;

class CreateInvoiceAction
{
    public function __construct(
        private readonly SWSapienService $swService,
    ) {}

    /**
     * Orchestrate the creation of a new CFDI invoice via SW Sapien.
     *
     * 1. Persist invoice + items.
     * 2. Sync missing fiscal data back to the Customer model.
     * 3. Stamp the invoice via SW Sapien HTTP API.
     */
    public function execute(array $data, User $user): Invoice
    {
        return DB::transaction(function () use ($data, $user) {
            // 1. Persist
            $invoice = $this->swService->createInvoice($data, $user->branch_id);

            // 2. Auto-update customer fiscal data if new/changed
            if (! empty($data['customer_id'])) {
                $this->swService->syncCustomerFiscalData($data['customer_id'], $data);
            }

            // 3. Stamp via SW Sapien
            $this->swService->stamp($invoice);

            return $invoice->fresh('items');
        });
    }
}
