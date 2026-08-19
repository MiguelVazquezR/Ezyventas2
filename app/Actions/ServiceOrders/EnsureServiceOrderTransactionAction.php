<?php

namespace App\Actions\ServiceOrders;

use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\ServiceOrder;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class EnsureServiceOrderTransactionAction
{
    /**
     * Garantiza que la orden de servicio tenga una venta (transacción) asociada
     * para poder registrar pagos. Si ya existe, la devuelve; si no, la crea con
     * los totales de la orden.
     *
     * Las órdenes creadas antes de que se vincularan las ventas (o sin cliente)
     * pueden no tener transacción; esta acción las repara al vuelo.
     */
    public function execute(ServiceOrder $serviceOrder, int $userId): Transaction
    {
        if ($serviceOrder->transaction) {
            return $serviceOrder->transaction;
        }

        return DB::transaction(function () use ($serviceOrder, $userId) {
            $transaction = $serviceOrder->transaction()->create([
                'folio' => $this->generateTransactionFolio($serviceOrder->branch_id),
                'customer_id' => $serviceOrder->customer_id,
                'branch_id' => $serviceOrder->branch_id,
                'user_id' => $userId,
                'subtotal' => $serviceOrder->subtotal,
                'total_discount' => $serviceOrder->discount_amount,
                'total_tax' => 0,
                'channel' => TransactionChannel::SERVICE_ORDER,
                'status' => $serviceOrder->final_total > 0
                    ? TransactionStatus::PENDING
                    : TransactionStatus::COMPLETED,
            ]);

            return $transaction;
        });
    }

    private function generateTransactionFolio(int $branchId): string
    {
        $lastTransaction = Transaction::where('branch_id', $branchId)
            ->where('folio', 'like', 'OS-V-%')
            ->orderByRaw('CAST(SUBSTRING(folio, 6) AS UNSIGNED) DESC')
            ->first();

        $sequence = $lastTransaction ? ((int) substr($lastTransaction->folio, 5)) + 1 : 1;

        return 'OS-V-' . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }
}
