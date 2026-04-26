<?php

namespace App\Actions\Transactions;

use App\Enums\CustomerBalanceMovementType;
use App\Enums\PaymentMethod;
use App\Enums\SessionCashMovementType;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\TransactionPaymentService;
use Exception;
use Illuminate\Support\Facades\DB;

class ProcessProductExchange
{
    public function __construct(
        protected TransactionPaymentService $transactionPaymentService,
        protected PaymentService $paymentService
    ) {}

    /**
     * Procesa un CAMBIO de producto (Exchange).
     * Devuelve items al inventario, conserva los no devueltos y agrega los nuevos.
     */
    public function execute(User $user, Transaction $originalTransaction, array $data): Transaction
    {
        return DB::transaction(function () use ($user, $originalTransaction, $data) {
            $now = now();
            $sessionId = $data['cash_register_session_id'];
            $customer = isset($data['new_customer_id']) ? Customer::find($data['new_customer_id']) : $originalTransaction->customer;

            if (isset($data['new_customer_id']) && !$originalTransaction->customer_id) {
                $originalTransaction->update(['customer_id' => $data['new_customer_id']]);
            }

            // 1. Mapear devoluciones y calcular Artículos Conservados
            $returnedItemsMap = [];
            foreach ($data['returned_items'] as $ret) {
                $returnedItemsMap[$ret['item_id']] = ($returnedItemsMap[$ret['item_id']] ?? 0) + $ret['quantity'];
            }

            $keptItemsData = [];
            $keptSubtotal = 0;
            $keptDiscount = 0;

            foreach ($originalTransaction->items as $originalItem) {
                $retQty = $returnedItemsMap[$originalItem->id] ?? 0;
                $keptQty = $originalItem->quantity - $retQty;

                if ($keptQty > 0) {
                    $proportionalDiscount = ($originalItem->quantity > 0) 
                        ? ($originalItem->discount_amount / $originalItem->quantity) * $keptQty 
                        : 0;

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
                        $itemModel->restock($originalTransaction->branch_id, $retQty, $user, "Retorno de stock por cambio {$originalTransaction->folio}");
                    }
                }
            }

            // 2. Calcular los totales de los artículos NUEVOS
            $newSubtotal = 0;
            $newDiscount = 0;
            foreach ($data['new_items'] as $newItem) {
                $newSubtotal += ($newItem['quantity'] * $newItem['unit_price']);
                $newDiscount += ($newItem['discount'] ?? 0);
            }

            // 3. Crear Nueva Transacción con los Totales REALES (Conservados + Nuevos)
            $newTransaction = Transaction::create([
                'cash_register_session_id' => $sessionId,
                'folio' => Transaction::generateFolio($user->branch_id),
                'customer_id' => $customer?->id,
                'branch_id' => $user->branch_id,
                'user_id' => $user->id,
                'status' => TransactionStatus::COMPLETED,
                'channel' => TransactionChannel::POS,
                'subtotal' => $keptSubtotal + $newSubtotal,
                'total_discount' => $keptDiscount + $newDiscount,
                'currency' => 'MXN',
                'notes' => "Cambio de producto ref. Venta Original #{$originalTransaction->folio}. " . ($data['notes'] ?? ''),
                'status_changed_at' => $now,
            ]);

            // 4. Inyectar los artículos conservados (SIN mover stock)
            foreach ($keptItemsData as $keptItem) {
                $newTransaction->items()->create($keptItem);
            }

            // 5. Inyectar los artículos nuevos (ESTOS SÍ descuentan stock)
            $this->transactionPaymentService->createTransactionItems($newTransaction, $data['new_items'], TransactionStatus::COMPLETED);

            // 6. Transferencia de Fondos (Pago Virtual)
            $newTotalSale = (float) $newTransaction->total;
            $totalPaidOnOriginal = $originalTransaction->total_paid;
            $exchangePaymentAmount = min($newTotalSale, $totalPaidOnOriginal);

            if ($exchangePaymentAmount > 0) {
                $this->paymentService->processPayments($newTransaction, [[
                    'amount' => $exchangePaymentAmount,
                    'method' => PaymentMethod::EXCHANGE->value,
                    'notes' => "Transferencia de pago desde venta #{$originalTransaction->folio}",
                    'bank_account_id' => null,
                ]], $sessionId);
            }

            // 7. Manejar Estatus Original
            if (!in_array($originalTransaction->status, [TransactionStatus::CANCELLED, TransactionStatus::REFUNDED])) {
                if ($customer && in_array($originalTransaction->status, [TransactionStatus::PENDING, TransactionStatus::ON_LAYAWAY])) {
                    $pendingAmount = $originalTransaction->remaining_due;
                    if ($pendingAmount > 0.01) {
                        $customer->cancelDebt($pendingAmount, $originalTransaction->id, "Ajuste por cambio. Deuda transferida a nueva venta #{$newTransaction->folio}", clone $now);
                    }
                }
                $originalTransactionStatus = $originalTransaction->status;
                $originalTransaction->update(['status' => TransactionStatus::CHANGED]);
            }

            // 8. Manejar Diferencia Financiera
            $remainingToPay = $newTotalSale - $exchangePaymentAmount;

            if ($remainingToPay > 0.01 && !empty($data['use_balance']) && $customer && $customer->balance > 0) {
                $balanceToUse = min($remainingToPay, (float) $customer->balance);
                if ($balanceToUse > 0) {
                    $this->transactionPaymentService->applyBalanceAsPayment($newTransaction, $customer, $balanceToUse, $sessionId, "Uso de saldo en cambio #{$newTransaction->folio}", clone $now);
                    $remainingToPay -= $balanceToUse;
                }
            }

            if ($remainingToPay > 0.01) {
                if (!empty($data['payments'])) {
                    $this->transactionPaymentService->applyDirectPayments($newTransaction, $data['payments'], $sessionId);
                    $remainingToPay = $newTransaction->fresh()->remaining_due;
                }

                if ($remainingToPay > 0.01) {
                    $useCredit = $data['use_credit_for_shortage'] ?? in_array($originalTransactionStatus, [TransactionStatus::PENDING, TransactionStatus::ON_LAYAWAY]);
                    
                    if ($useCredit && $customer && $remainingToPay <= $customer->available_credit) {
                        $customer->addDebt($remainingToPay, CustomerBalanceMovementType::CREDIT_SALE, $newTransaction->id, "Saldo pendiente por cambio. Venta #{$newTransaction->folio}", $now->copy()->addSecond());
                        $newTransaction->update(['status' => TransactionStatus::PENDING]);
                    } else {
                        throw new Exception("El monto cubierto no es suficiente o crédito denegado.");
                    }
                }
            } elseif ($newTotalSale < $totalPaidOnOriginal - 0.01) {
                $excessPayment = $totalPaidOnOriginal - $newTotalSale;

                if (isset($data['debts_to_pay']) && is_array($data['debts_to_pay']) && $customer) {
                    foreach ($data['debts_to_pay'] as $debtToPay) {
                        if ($excessPayment <= 0.01) break;
                        $targetTxn = Transaction::find($debtToPay['id']);
                        if (!$targetTxn || $targetTxn->customer_id !== $customer->id) continue;

                        $amountToPay = min((float)$debtToPay['amount'], $targetTxn->remaining_due, $excessPayment);

                        if ($amountToPay > 0) {
                            $this->paymentService->processPayments($targetTxn, [[
                                'amount' => $amountToPay,
                                'method' => PaymentMethod::EXCHANGE->value,
                                'notes' => "Cubierto con excedente de cambio venta #{$newTransaction->folio}",
                                'bank_account_id' => null,
                            ]], $sessionId);

                            if ($targetTxn->fresh()->isFullyPaid()) {
                                $targetTxn->update(['status' => TransactionStatus::COMPLETED]);
                                if ($targetTxn->status === TransactionStatus::ON_LAYAWAY) {
                                    $this->transactionPaymentService->finalizeTransactionStock($targetTxn, clone $now);
                                }
                            }

                            $customer->payDebt($amountToPay, $targetTxn->id, "Abono liquidado con excedente de cambio #{$newTransaction->folio}", $now->copy()->addSecond());
                            $excessPayment -= $amountToPay;
                        }
                    }
                }

                if ($excessPayment > 0.01) {
                    if (($data['exchange_refund_type'] ?? 'balance') === 'balance') {
                        $customer->addRefund($excessPayment, $newTransaction->id, "Saldo a favor restante por cambio (Excedente). Venta #{$newTransaction->folio}", $now->copy()->addSecond());
                    } else {
                        $user->cashRegisterSessions()->find($sessionId)?->cashMovements()->create([
                            'user_id' => $user->id,
                            'type' => SessionCashMovementType::OUTFLOW,
                            'amount' => $excessPayment,
                            'description' => "Devolución efectivo cambio #{$newTransaction->folio}",
                            'notes' => "Diferencia a favor entregada al cliente.",
                        ]);
                    }
                }
            }

            return $newTransaction;
        });
    }
}