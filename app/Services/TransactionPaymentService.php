<?php

namespace App\Services;

use App\Enums\CustomerBalanceMovementType;
use App\Enums\PaymentMethod;
use App\Enums\SessionCashMovementType;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

/**
 * Servicio para orquestar operaciones de pago complejas que
 * involucran transacciones, clientes y movimientos de saldo.
 */
class TransactionPaymentService
{
    public function __construct(protected PaymentService $paymentService) {}

    public function handleNewSale(
        array $validatedData,
        User $user,
        ?Customer $customer,
        TransactionStatus $initialStatus,
        ?CustomerBalanceMovementType $debtType
    ): Transaction {
        return DB::transaction(function () use ($validatedData, $user, $customer, $initialStatus, $debtType) {
            $now = now();
            $totalSale = (float) $validatedData['total'];
            $paymentsFromRequest = $validatedData['payments'] ?? [];
            $sessionId = $validatedData['cash_register_session_id'];

            // 1. Crear la Transacción (Folio generado desde el modelo)
            $transaction = Transaction::create([
                'cash_register_session_id' => $sessionId,
                'folio' => Transaction::generateFolio($user->branch_id),
                'customer_id' => $customer?->id,
                'contact_info' => !empty($validatedData['guest_name']) ? ['name' => $validatedData['guest_name']] : null,
                'branch_id' => $user->branch_id,
                'user_id' => $user->id,
                'status' => $initialStatus,
                'channel' => TransactionChannel::POS,
                'subtotal' => $validatedData['subtotal'],
                'total_discount' => $validatedData['total_discount'] ?? 0,
                'total_tax' => 0,
                'currency' => 'MXN',
                'status_changed_at' => $now,
                'layaway_expiration_date' => $validatedData['layaway_expiration_date'] ?? null,
            ]);

            // 2. Crear Items y manejar Stock delegando a los Modelos
            $this->createTransactionItems($transaction, $validatedData['cartItems'], $initialStatus);

            $balanceToUse = 0;

            // 3. Aplicar Saldo a Favor (si se usa)
            if ($validatedData['use_balance'] && $customer && $customer->balance > 0) {
                $balanceToUse = min($totalSale, (float) $customer->balance);
                if ($balanceToUse > 0) {
                    $this->applyBalanceAsPayment($transaction, $customer, $balanceToUse, $sessionId, "Uso de saldo en venta POS #{$transaction->folio}", clone $now);
                }
            }

            // 4. Aplicar Pagos Directos
            $totalDue = $totalSale - $balanceToUse;
            if (!empty($paymentsFromRequest)) {
                $paymentsToProcess = $this->capPaymentsToAmount($paymentsFromRequest, $totalDue);
                $this->applyDirectPayments($transaction, $paymentsToProcess, $sessionId);
            }

            // 5. Calcular estado final y gestionar deuda
            $transaction->refresh();
            $remainingDue = $transaction->remaining_due;

            if ($remainingDue > 0.01) {
                if (!$customer || ($debtType === CustomerBalanceMovementType::CREDIT_SALE && $remainingDue > $customer->available_credit)) {
                    throw new Exception("Pago insuficiente y el cliente no tiene crédito disponible.");
                }
                // REFACTOR: Uso del método del modelo Customer
                $customer->addDebt($remainingDue, $debtType, $transaction->id, "Cargo a saldo por venta #{$transaction->folio}", $now->copy()->addSecond());
            } else {
                $transaction->update(['status' => TransactionStatus::COMPLETED]);
                if ($initialStatus === TransactionStatus::ON_LAYAWAY) {
                    $this->finalizeTransactionStock($transaction, clone $now);
                }
            }

            return $transaction;
        });
    }

    public function handleNewOrder(User $user, array $data): Transaction
    {
        return DB::transaction(function () use ($user, $data) {
            $now = now();
            $sessionId = $data['cash_register_session_id'];

            $transaction = Transaction::create([
                'cash_register_session_id' => $sessionId,
                'folio' => Transaction::generateFolio($user->branch_id),
                'customer_id' => $data['customer_id'] ?? null,
                'contact_info' => $data['contact_info'] ?? null,
                'branch_id' => $user->branch_id,
                'user_id' => $user->id,
                'status' => TransactionStatus::TO_DELIVER,
                'delivery_status' => 'pending',
                'channel' => TransactionChannel::POS,
                'subtotal' => $data['subtotal'],
                'shipping_cost' => $data['shipping_cost'] ?? 0,
                'total_discount' => $data['total_discount'] ?? 0,
                'total_tax' => 0,
                'currency' => 'MXN',
                'notes' => $data['notes'] ?? null,
                'delivery_date' => $data['delivery_date'] ?? null,
                'shipping_address' => $data['shipping_address'] ?? null,
                'status_changed_at' => $now,
            ]);

            $this->createTransactionItems($transaction, $data['cartItems'], TransactionStatus::TO_DELIVER);

            return $transaction;
        });
    }

    /**
     * Procesa un CAMBIO de producto (Exchange).
     * Devuelve items al inventario, conserva los no devueltos y agrega los nuevos.
     */
    public function handleProductExchange(User $user, Transaction $originalTransaction, array $data): Transaction 
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

                // Si el cliente se quedó con una parte (o todo) de esta partida, lo preparamos para clonar
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

                // Procesar stock solo de lo que SÍ se devolvió
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

            // 4. Inyectar los artículos conservados (SIN mover stock, porque ya salieron de la tienda antes)
            foreach ($keptItemsData as $keptItem) {
                $newTransaction->items()->create($keptItem);
            }

            // 5. Inyectar los artículos nuevos (ESTOS SÍ descuentan stock)
            $this->createTransactionItems($newTransaction, $data['new_items'], TransactionStatus::COMPLETED);

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
                    $this->applyBalanceAsPayment($newTransaction, $customer, $balanceToUse, $sessionId, "Uso de saldo en cambio #{$newTransaction->folio}", clone $now);
                    $remainingToPay -= $balanceToUse;
                }
            }

            if ($remainingToPay > 0.01) {
                if (!empty($data['payments'])) {
                    $this->applyDirectPayments($newTransaction, $data['payments'], $sessionId);
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
                                if ($targetTxn->status === TransactionStatus::ON_LAYAWAY) $this->finalizeTransactionStock($targetTxn, clone $now);
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

    /**
     * Procesa específicamente cambios en un APARTADO. Conserva artículos en el nuevo apartado.
     */
    public function handleLayawayExchange(User $user, Transaction $originalTransaction, array $data): Transaction 
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
            $this->createTransactionItems($newTransaction, $data['new_items'], TransactionStatus::ON_LAYAWAY);

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
                $this->applyDirectPayments($newTransaction, $data['payments'], $sessionId);
            }

            $newTransaction->refresh();
            $remainingBalance = $newTransaction->remaining_due;

            if ($newTransaction->isFullyPaid()) {
                $newTransaction->update(['status' => TransactionStatus::COMPLETED]);
                $this->finalizeTransactionStock($newTransaction, clone $now);

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

    public function applyPaymentToTransaction(Transaction $transaction, array $validatedData, int $sessionId): void
    {
        DB::transaction(function () use ($transaction, $validatedData, $sessionId) {
            $customer = $transaction->customer;
            $now = now();
            $remainingDue = $transaction->remaining_due;

            // NUEVO: Capturar el estatus original antes de que cambie
            $originalStatus = $transaction->status;

            if ($remainingDue <= 0.01) throw new Exception('Esta transacción ya está completamente pagada.');

            $balanceToUse = (!empty($validatedData['use_balance']) && $customer) ? min($customer->balance, $remainingDue) : 0;
            $totalFromPayments = !empty($validatedData['payments']) ? array_sum(array_column($validatedData['payments'], 'amount')) : 0;

            if (($balanceToUse + $totalFromPayments) > $remainingDue + 0.01) {
                throw new Exception('El monto total del pago excede el saldo pendiente.');
            }

            if ($balanceToUse > 0) {
                $this->applyBalanceAsPayment($transaction, $customer, $balanceToUse, $sessionId, "Uso de saldo a favor en abono #{$transaction->folio}", clone $now);
            }

            if ($totalFromPayments > 0) {
                $this->applyDirectPayments($transaction, $validatedData['payments'], $sessionId);
                if ($customer) {
                    $customer->payDebt($totalFromPayments, $transaction->id, "Abono a O.S. / Apartado #{$transaction->folio}", $now->copy()->addSecond());
                }
            }

            if ($transaction->fresh()->isFullyPaid()) {
                $transaction->update(['status' => TransactionStatus::COMPLETED]);

                // NUEVO: Ejecutar el descuento final del stock reservado y físico
                if ($originalStatus === TransactionStatus::ON_LAYAWAY) {
                    $this->finalizeTransactionStock($transaction, clone $now);
                }
            }
        });
    }

    public function applyPaymentToCustomerBalance(Customer $customer, array $validatedData, int $sessionId, User $user): void
    {
        DB::transaction(function () use ($customer, $validatedData, $sessionId, $user) {
            $now = now();
            $pendingTransactions = $customer->transactions()->whereIn('status', [TransactionStatus::PENDING, TransactionStatus::ON_LAYAWAY])->orderBy('created_at', 'asc')->get();

            $baseTimestamp = $now->copy();
            $delayCounter = 0; // Para asegurar orden en el historial

            foreach ($validatedData['payments'] as $paymentData) {
                $amountToApply = (float) $paymentData['amount'];

                foreach ($pendingTransactions as $transaction) {
                    if ($amountToApply <= 0.001) break;

                    $originalStatus = $transaction->status;
                    $pendingAmount = $transaction->remaining_due;
                    if ($pendingAmount <= 0.001) continue;

                    $amountForThisTransaction = min($amountToApply, $pendingAmount);

                    $this->paymentService->processPayments($transaction, [[
                        'amount' => $amountForThisTransaction,
                        'method' => $paymentData['method'],
                        'notes' => 'Abono a deuda. ' . ($validatedData['notes'] ?? ''),
                        'bank_account_id' => $paymentData['bank_account_id'] ?? null,
                    ]], $sessionId);

                    if ($transaction->fresh()->isFullyPaid()) {
                        $transaction->update(['status' => TransactionStatus::COMPLETED]);
                        if ($originalStatus === TransactionStatus::ON_LAYAWAY) $this->finalizeTransactionStock($transaction, clone $now);
                    }

                    // REFACTOR: Usando payDebt del cliente con un timestamp consecutivo
                    $customer->payDebt($amountForThisTransaction, $transaction->id, "Abono a la venta #{$transaction->folio} (" . $paymentData['method'] . "). " . ($validatedData['notes'] ?? ''), $baseTimestamp->copy()->addSeconds($delayCounter++));
                    $amountToApply -= $amountForThisTransaction;
                }

                if ($amountToApply > 0.001) {
                    $balanceTransaction = $customer->transactions()->create([
                        'folio' => Transaction::generateBalancePaymentFolio($user->branch_id),
                        'branch_id' => $user->branch_id,
                        'user_id' => $user->id,
                        'cash_register_session_id' => $sessionId,
                        'subtotal' => $amountToApply,
                        'channel' => TransactionChannel::BALANCE_PAYMENT,
                        'status' => TransactionStatus::COMPLETED,
                        'notes' => 'Transacción generada para registrar abono a saldo a favor.',
                        'created_at' => clone $now,
                    ]);

                    $this->paymentService->processPayments($balanceTransaction, [[
                        'amount' => $amountToApply,
                        'method' => $paymentData['method'],
                        'notes' => 'Abono directo a saldo. ' . ($validatedData['notes'] ?? ''),
                        'bank_account_id' => $paymentData['bank_account_id'] ?? null,
                    ]], $sessionId);

                    $customer->addRefund($amountToApply, $balanceTransaction->id, "Abono a saldo a favor. " . ($validatedData['notes'] ?? ''), $baseTimestamp->copy()->addSeconds($delayCounter++));
                }
            }
        });
    }

    // --- MÉTODOS PRIVADOS DE AYUDA EXTREMADAMENTE REDUCIDOS ---

    private function applyBalanceAsPayment(Transaction $transaction, Customer $customer, float $amountToUse, int $sessionId, string $notes, Carbon $timestamp): void
    {
        $this->paymentService->processPayments($transaction, [[
            'amount' => $amountToUse,
            'method' => PaymentMethod::BALANCE->value,
            'notes' => $notes,
            'bank_account_id' => null,
        ]], $sessionId);
        $customer->useBalance($amountToUse, $transaction->id, $notes, $timestamp);
    }

    private function applyDirectPayments(Transaction $transaction, array $payments, int $sessionId): void
    {
        if (!empty($payments)) $this->paymentService->processPayments($transaction, $payments, $sessionId);
    }

    /**
     * REFACTOR MÁXIMO: Este bloque de 150 líneas ahora solo tiene 20. Delega la responsabilidad de stock al Modelo.
     */
    private function createTransactionItems(Transaction $transaction, array $cartItems, TransactionStatus $status): void
    {
        $branchId = $transaction->branch_id;
        $user = Auth::user() ?? $transaction->user;

        foreach ($cartItems as $item) {
            $itemableId = $item['product_attribute_id'] ?? $item['id'];
            $itemableType = !empty($item['product_attribute_id']) ? ProductAttribute::class : Product::class;
            $itemModel = $itemableType::find($itemableId);

            $transaction->items()->create([
                'itemable_id' => $itemableId,
                'itemable_type' => $itemableType,
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'discount_amount' => $item['discount'],
                'discount_reason' => $item['discount_reason'] ?? null,
                'line_total' => $item['quantity'] * $item['unit_price'],
            ]);

            if ($itemModel) {
                $isReservation = in_array($status, [TransactionStatus::ON_LAYAWAY, TransactionStatus::TO_DELIVER]);
                $description = "Actualización de stock por " . ($isReservation ? 'reserva/apartado' : 'venta') . " {$transaction->folio}";

                if ($isReservation) {
                    $itemModel->reserveStock($branchId, $item['quantity'], $user, $description);
                } else {
                    $itemModel->deductStock($branchId, $item['quantity'], $user, $description);
                }
            }
        }
    }

    /**
     * REFACTOR MÁXIMO: Delega la finalización de los apartados al Modelo que sepa cómo manejarse.
     */
    private function finalizeTransactionStock(Transaction $transaction, Carbon $timestamp): void
    {
        $branchId = $transaction->branch_id;
        $user = Auth::user() ?? $transaction->user;

        foreach ($transaction->items as $txnItem) {
            if ($itemModel = $txnItem->itemable) {
                $itemModel->finalizeLayawayStock($branchId, $txnItem->quantity, $user, "Baja de reserva por liquidación {$transaction->folio}");
            }
        }
    }

    private function capPaymentsToAmount(array $payments, float $maxAmount): array
    {
        $totalPaid = collect($payments)->sum('amount');
        if ($totalPaid <= $maxAmount) return $payments;

        $cappedPayments = [];
        $runningTotal = 0;
        foreach ($payments as $payment) {
            $amountToCap = $maxAmount - $runningTotal;
            if ($amountToCap <= 0) break;

            $amountToRecord = min((float) $payment['amount'], $amountToCap);
            $cappedPayments[] = array_merge($payment, ['amount' => $amountToRecord]);
            $runningTotal += $amountToRecord;
        }
        return $cappedPayments;
    }
}