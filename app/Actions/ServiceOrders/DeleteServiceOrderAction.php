<?php

namespace App\Actions\ServiceOrders;

use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a service order together with the sale linked to it.
 *
 * Creating the order took the parts out of stock and charged the customer, so
 * deleting it undoes both effects: the same reversion as cancelling the order.
 * The mobile app asks for an explicit confirmation before calling this
 * ("Esta acción no se puede deshacer.").
 */
class DeleteServiceOrderAction
{
    public function execute(ServiceOrder $serviceOrder, User $user): void
    {
        DB::transaction(function () use ($serviceOrder, $user) {
            $serviceOrder->load('items', 'transaction.customer');

            $serviceOrder->restoreStock($user, "Eliminación de O.S. #{$serviceOrder->folio}");

            $this->cancelCustomerDebt($serviceOrder);

            $serviceOrder->transaction()->delete();
            $serviceOrder->delete();
        });
    }

    /**
     * Cancels the debt the order charged to the customer on creation. What was
     * already collected (advance payments) is not refunded here, exactly as in
     * the cancellation flow.
     */
    private function cancelCustomerDebt(ServiceOrder $serviceOrder): void
    {
        $transaction = $serviceOrder->transaction;
        $customer = $transaction?->customer;

        if (!$customer || !$transaction || $transaction->isFullyPaid()) {
            return;
        }

        $customer->cancelDebt(
            amount: (float) $transaction->remaining_due,
            transactionId: $transaction->id,
            notes: "Crédito por eliminación de O.S. #{$serviceOrder->folio}"
        );
    }
}
