<?php

namespace App\Actions\Quote;

use App\Enums\CustomerBalanceMovementType;
use App\Enums\QuoteStatus;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ChangeQuoteStatusAction
{
    /**
     * Ejecuta el cambio de estatus de una cotización y maneja las implicaciones de cancelación.
     */
    public function execute(Quote $quote, QuoteStatus $newStatus, User $user): array
    {
        $oldStatus = $quote->status;

        if ($oldStatus->value === $newStatus->value) {
            return ['success' => false, 'message' => 'El estatus ya es el seleccionado.'];
        }

        $message = 'Estatus de la cotización actualizado.';

        if ($newStatus === QuoteStatus::CANCELLED && $oldStatus === QuoteStatus::SALE_GENERATED && $quote->transaction_id) {
            
            DB::transaction(function () use ($quote, $user) {
                $quote->load(['transaction.payments']);
                $transaction = $quote->transaction;

                if ($transaction && $transaction->status !== TransactionStatus::CANCELLED && $transaction->status !== TransactionStatus::REFUNDED) {
                    
                    // 1. Devolver el stock a través del modelo
                    $quote->returnStockFromCancelledSale($user);

                    // 2. Determinar si se reembolsa o solo se cancela la transacción
                    $totalPaid = $transaction->total_paid; // Usamos el Accesor
                    $transaction->update(['status' => $totalPaid > 0 ? TransactionStatus::REFUNDED : TransactionStatus::CANCELLED]);

                    // 3. Devolver saldo a favor al cliente por la deuda cancelada
                    if ($transaction->customer_id) {
                        $customer = Customer::find($transaction->customer_id);
                        if ($customer) {
                            $creditAmount = $transaction->total; // Usamos el Accesor
                            $customer->cancelDebt(
                                amount: $creditAmount,
                                transactionId: $transaction->id,
                                notes: "Crédito por cancelación de Venta derivada de Cotización #{$quote->folio}"
                            );
                        }
                    }
                }
            });

            $message = 'Cotización cancelada, venta revertida y stock devuelto.';
        }

        $quote->update(['status' => $newStatus->value]);

        return ['success' => true, 'message' => $message];
    }
}