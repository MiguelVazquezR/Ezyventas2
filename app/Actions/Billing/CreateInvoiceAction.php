<?php

namespace App\Actions\Billing;

use App\Exceptions\Billing\InsufficientStampsException;
use App\Models\Billing\Invoice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Billing\SWSapienService;
use Illuminate\Support\Facades\DB;

class CreateInvoiceAction
{
    public function __construct(
        private readonly SWSapienService $swService,
        private readonly StampInvoiceAction $stampInvoiceAction,
    ) {}

    /**
     * Orchestrate the creation of a new CFDI invoice via SW Sapien.
     *
     * 1. Persist invoice + items (its own transaction).
     * 2. Sync missing fiscal data back to the Customer model.
     * 3. Stamp via the reservation flow (unless draft mode) — the stamping
     *    runs AFTER the creation transaction commits so the profile row lock
     *    is not held during the HTTP call to the PAC.
     */
    public function execute(array $data, User $user, bool $draft = false): Invoice
    {
        $invoice = DB::transaction(function () use ($data, $user) {
            // 1. Persist
            $invoice = $this->swService->createInvoice($data, $user->branch_id);

            // 2. Mark the linked POS sale as invoiced (1:1 relation).
            // The flag gates the sale-search endpoint, so the same sale can
            // never be linked to a second invoice.
            if (! empty($data['transaction_id'])) {
                Transaction::where('id', $data['transaction_id'])
                    ->where('branch_id', $user->branch_id)
                    ->update(['invoiced' => true]);
            }

            // 3. Auto-update customer fiscal data if new/changed
            if (! empty($data['customer_id'])) {
                $this->swService->syncCustomerFiscalData($data['customer_id'], $data);
            }

            // 4. Persist SAT codes filled on the concepts back to the product
            // / service catalog when they are still empty, so they come
            // pre-filled the next time the product or service is selected.
            $this->swService->syncConceptCatalogData($data['items'] ?? []);

            return $invoice;
        });

        $invoice = $invoice->fresh('items');

        // 3. Stamp via the reservation flow — skip if draft mode.
        // Falta de timbres NO es un error: la factura queda guardada como
        // prefactura y el controlador muestra el aviso correspondiente.
        if (! $draft) {
            try {
                $this->stampInvoiceAction->execute($invoice);
            } catch (InsufficientStampsException $e) {
                $invoice->stamp_blocked = $e->getMessage();
            }
        }

        return $invoice;
    }
}
