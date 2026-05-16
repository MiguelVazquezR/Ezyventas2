<?php

namespace App\Actions\Quote;

use App\Enums\CustomerBalanceMovementType;
use App\Enums\QuoteStatus;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Exception;

class ConvertQuoteToSaleAction
{
    public function execute(Quote $quote, User $user): Transaction
    {
        if ($quote->status !== QuoteStatus::AUTHORIZED) {
            throw new Exception('Solo las cotizaciones autorizadas pueden convertirse en venta.');
        }
        
        if ($quote->transaction_id) {
            throw new Exception('Esta cotización ya tiene una venta asociada.');
        }

        return DB::transaction(function () use ($quote, $user) {
            // 1. Crear Transacción (usamos el generador de folios que ya existe en el modelo Transaction)
            $transaction = Transaction::create([
                'folio' => Transaction::generateFolio($user->branch_id),
                'customer_id' => $quote->customer_id,
                'branch_id' => $user->branch_id,
                'user_id' => $user->id,
                'transactionable_id' => $quote->id,
                'transactionable_type' => Quote::class,
                'status' => TransactionStatus::PENDING,
                'channel' => TransactionChannel::QUOTE,
                'subtotal' => $quote->subtotal,
                'total_discount' => $quote->total_discount,
                'total_tax' => $quote->total_tax,
            ]);

            // 2. Copiar Items de la Cotización a la Transacción
            foreach ($quote->items as $quoteItem) {
                $transaction->items()->create([
                    'itemable_id' => $quoteItem->itemable_id,
                    'itemable_type' => $quoteItem->itemable_type,
                    'description' => $quoteItem->description,
                    'quantity' => $quoteItem->quantity,
                    'unit_price' => $quoteItem->unit_price,
                    'discount_amount' => 0,
                    'tax_amount' => 0,
                    'line_total' => $quoteItem->line_total,
                ]);
            }

            // 3. Descontar stock delegando la responsabilidad a la cotización
            $quote->deductStockForSale($user);

            // 4. Actualizar el estado de la cotización
            $quote->update([
                'status' => QuoteStatus::SALE_GENERATED,
                'transaction_id' => $transaction->id,
            ]);

            // 5. Registrar el cargo en el balance del cliente (Deuda)
            if ($quote->customer_id) {
                $customer = Customer::find($quote->customer_id);
                if ($customer) {
                    $customer->addDebt(
                        amount: $quote->total_amount,
                        debtType: CustomerBalanceMovementType::CREDIT_SALE,
                        transactionId: $transaction->id,
                        notes: "Cargo por Venta originada de Cotización #{$quote->folio}"
                    );
                }
            }

            return $transaction;
        });
    }
}