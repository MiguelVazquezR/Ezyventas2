<?php

namespace App\Actions\Transactions;

use App\Enums\CustomerBalanceMovementType;
use App\Enums\PaymentMethod;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\TransactionPaymentService;
use Exception;
use Illuminate\Support\Facades\DB;

class ProcessLayawayExchange
{
    public function __construct(
        protected TransactionPaymentService $transactionPaymentService,
        protected PaymentService $paymentService
    ) {}

    /**
     * Procesa específicamente cambios en un APARTADO. Conserva artículos en el nuevo apartado.
     */
    public function execute(User $user, Transaction $originalTransaction, array $data): Transaction 
    {
        return DB::transaction(function () use ($user, $originalTransaction, $data) {
            $now = now();
            $sessionId = $data['cash_register_session_id'];
            $customer = Customer::findOrFail($data['new_customer_id'] ?? $originalTransaction->customer_id);

            if ($originalTransaction->status !== TransactionStatus::ON_LAYAWAY) throw new Exception("Solo para Apartados.");

            $returnedItemsMap = [];
            foreach ($data['returned_items'] as $ret) {
                $returnedItemsMap[$ret['item_id']] = ($returnedItemsMap[$ret['item_id']] ?? 0) + $ret['quantity'];
            }

            $keptItemsData = [];
            $keptSubtotal = 0;
            $keptDiscount = 0;

            // 1. Procesar Devoluciones (Liberar Reserva) y separar Conservados
            foreach ($originalTransaction->items as $originalItem) {
                $retQty = $returnedItemsMap[$originalItem->id] ?? 0;
                $keptQty = $originalItem->quantity - $retQty;

                if ($keptQty > 0) {
                    $proportionalDiscount = ($originalItem->quantity > 0) ? ($originalItem->discount_amount / $originalItem->quantity) * $keptQty : 0;
                    $keptItemsData[] = [
                        'itemable_id' => $originalItem->itemable_id,
                        'itemable_type' => $originalItem->itemable_type,
                        'description' => $originalItem->description,
                        'quantity' => $keptQty,
                        'unit_price' => $originalItem->unit_price,
                        'discount_amount' => $proportionalDiscount,
                        'discount_reason' => $originalItem->discount_reason,
                        'line_total' => ($originalItem->unit_price * $keptQty) - $proportionalDiscount,
                    ];
                    $keptSubtotal += ($originalItem->unit_price * $keptQty);
                    $keptDiscount += $proportionalDiscount;
                }

                if ($retQty > 0) {
                    $itemModel = $originalItem->itemable ?? (class_exists($originalItem->itemable_type) ? $originalItem->itemable_type::find($originalItem->itemable_id) : null);
                    if ($itemModel) {
                        $itemModel->releaseLayawayStock($originalTransaction->branch_id, $retQty, $user, "Liberación de reserva por modificación apartado {$originalTransaction->folio}");
                    }
                }
            }

            $newSubtotal = 0;
            $newDiscount = 0;
            foreach ($data['new_items'] as $newItem) {
                $newSubtotal += ($newItem['quantity'] * $newItem['unit_price']);
                $newDiscount += ($newItem['discount'] ?? 0);
            }

            // 2. Crear Nueva Transacción (Inicialmente como Apartado)
            $newTransaction = Transaction::create([
                'cash_register_session_id' => $sessionId,
                'folio' => Transaction::generateFolio($user->branch_id),
                'customer_id' => $customer->id,
                'branch_id' => $user->branch_id,
                'user_id' => $user->id,
                'status' => TransactionStatus::ON_LAYAWAY,
                'channel' => TransactionChannel::POS,
                'subtotal' => $keptSubtotal + $newSubtotal,
                'total_discount' => $keptDiscount + $newDiscount,
                'currency' => 'MXN',
                'notes' => "Modificación de apartado. Ref. Original #{$originalTransaction->folio}. " . ($data['notes'] ?? ''),
                'status_changed_at' => $now,
                'layaway_expiration_date' => $originalTransaction->layaway_expiration_date,
            ]);

            // 3. Inyectar conservados (SIN reservar stock, porque ya estaban reservados)
            foreach ($keptItemsData as $keptItem) {
                $newTransaction->items()->create($keptItem);
            }

            // 4. Crear Nuevos Items (ESTOS SÍ reservan stock)
            $this->transactionPaymentService->createTransactionItems($newTransaction, $data['new_items'], TransactionStatus::ON_LAYAWAY);

            // 5. Transferir Abonos Previos
            $previousPaymentsTotal = $originalTransaction->total_paid;
            if ($previousPaymentsTotal > 0) {
                $this->paymentService->processPayments($newTransaction, [[
                    'amount' => $previousPaymentsTotal,
                    'method' => PaymentMethod::EXCHANGE->value,
                    'notes' => "Transferencia de abonos del apartado #{$originalTransaction->folio}",
                    'bank_account_id' => null,
                ]], $sessionId);
            }

            $originalTransaction->update(['status' => TransactionStatus::CHANGED]);

            // 6. Calcular Estado Financiero
            if (!empty($data['payments'])) {
                $this->transactionPaymentService->applyDirectPayments($newTransaction, $data['payments'], $sessionId);
            }

            $newTransaction->refresh();
            $remainingBalance = $newTransaction->remaining_due;

            if ($newTransaction->isFullyPaid()) {
                $newTransaction->update(['status' => TransactionStatus::COMPLETED]);
                $this->transactionPaymentService->finalizeTransactionStock($newTransaction, clone $now);

                if ($remainingBalance < -0.01) {
                    $customer->addRefund(abs($remainingBalance), $newTransaction->id, "Saldo a favor modificación apartado #{$newTransaction->folio}", $now->copy()->addSecond());
                }
            } else {
                if ($originalTransaction->remaining_due > 0.01) {
                    $customer->cancelDebt($originalTransaction->remaining_due, $originalTransaction->id, "Cancelación deuda apartado #{$originalTransaction->folio} por modificación", clone $now);
                }

                $debtType = defined('App\Enums\CustomerBalanceMovementType::LAYAWAY_DEBT') ? CustomerBalanceMovementType::LAYAWAY_DEBT : CustomerBalanceMovementType::CREDIT_SALE;
                $customer->addDebt($remainingBalance, $debtType, $newTransaction->id, "Saldo pendiente modificación apartado #{$newTransaction->folio}", $now->copy()->addSecond());
            }

            return $newTransaction;
        });
    }
}