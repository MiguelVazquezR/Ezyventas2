<?php

namespace App\Services;

use App\Enums\CustomerBalanceMovementType;
use App\Enums\PaymentMethod;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Transaction;
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

            // 3. Aplicar Saldo a Favor (si el usuario explícitamente marcó usar saldo)
            if (!empty($validatedData['use_balance']) && $customer && $customer->balance > 0) {
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
                if (!$customer) {
                    throw new Exception("Pago insuficiente y el cliente no tiene crédito disponible.");
                }

                // --- FIX: COBRO AUTOMÁTICO DE SALDO ---
                // Si aún hay deuda y el cliente tiene saldo a favor, el sistema fuerza
                // el uso de ese saldo como PAGO antes de generar una deuda real.
                // Esto genera el registro de "Payment" tipo BALANCE y cuadra la transacción.
                if ($customer->balance > 0) {
                    $forcedBalanceToUse = min($remainingDue, (float) $customer->balance);
                    $this->applyBalanceAsPayment($transaction, $customer, $forcedBalanceToUse, $sessionId, "Cobro automático de saldo a favor por venta #{$transaction->folio}", clone $now);
                    
                    $transaction->refresh();
                    $remainingDue = $transaction->remaining_due;
                }

                // Si aún queda deuda después de agotar el saldo a favor, aplicamos la deuda.
                if ($remainingDue > 0.01) {
                    if ($debtType === CustomerBalanceMovementType::CREDIT_SALE && $remainingDue > $customer->available_credit) {
                        throw new Exception("Pago insuficiente y el cliente no tiene crédito disponible.");
                    }
                    $customer->addDebt($remainingDue, $debtType, $transaction->id, "Cargo a saldo por venta #{$transaction->folio}", $now->copy()->addSecond());
                }
            } 
            
            // 6. Evaluación final: ¿Se pagó completa? (Con pagos, saldo automático, etc.)
            if ($transaction->fresh()->isFullyPaid()) {
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

    public function applyPaymentToTransaction(Transaction $transaction, array $validatedData, int $sessionId): void
    {
        DB::transaction(function () use ($transaction, $validatedData, $sessionId) {
            $customer = $transaction->customer;
            $now = now();
            $remainingDue = $transaction->remaining_due;

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

                if ($originalStatus === TransactionStatus::ON_LAYAWAY) {
                    $this->finalizeTransactionStock($transaction, clone $now);
                }
            }
        });
    }

    public function applyPaymentToCustomerBalance(Customer $customer, array $validatedData, int $sessionId, User $user): array
    {
        return DB::transaction(function () use ($customer, $validatedData, $sessionId, $user) {
            $now = now();
            $pendingTransactions = $customer->transactions()->whereIn('status', [TransactionStatus::PENDING, TransactionStatus::ON_LAYAWAY])->orderBy('created_at', 'asc')->get();

            $baseTimestamp = $now->copy();
            $delayCounter = 0;

            // Seguimiento para el ticket de abono general.
            $appliedByTransaction = [];
            $affectedIds = [];
            $totalAbonado = 0.0;
            $balanceCredit = 0.0;

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

                    $customer->payDebt($amountForThisTransaction, $transaction->id, "Abono a la venta #{$transaction->folio} (" . $paymentData['method'] . "). " . ($validatedData['notes'] ?? ''), $baseTimestamp->copy()->addSeconds($delayCounter++));

                    $totalAbonado += $amountForThisTransaction;
                    $appliedByTransaction[$transaction->id] = ($appliedByTransaction[$transaction->id] ?? 0) + $amountForThisTransaction;
                    $affectedIds[$transaction->id] = $transaction->id;

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
                    $balanceCredit += $amountToApply;
                }
            }

            // --- Resumen para el ticket de abono general ---
            $affectedTransactions = Transaction::whereIn('id', array_values($affectedIds))->get();

            $breakdown = $affectedTransactions->map(function (Transaction $transaction) use ($appliedByTransaction) {
                $remaining = (float) $transaction->remaining_due;

                return [
                    'folio' => $transaction->folio,
                    'abonado' => round($appliedByTransaction[$transaction->id] ?? 0, 2),
                    'restante' => max(0, round($remaining, 2)),
                    'liquidada' => $remaining <= 0.01,
                ];
            })->values()->all();

            // Restante total y próximo vencimiento de las ventas aún pendientes.
            $stillPending = $customer->transactions()
                ->whereIn('status', [TransactionStatus::PENDING, TransactionStatus::ON_LAYAWAY])
                ->get();

            $totalRemaining = round($stillPending->sum(fn (Transaction $t) => (float) $t->remaining_due), 2);
            $nextExpiration = $stillPending
                ->pluck('layaway_expiration_date')
                ->filter()
                ->min();
            $nextExpiration = $nextExpiration ? Carbon::parse($nextExpiration)->format('d/m/Y') : null;

            return [
                'total_abonado' => round($totalAbonado, 2),
                'balance_credit' => round($balanceCredit, 2),
                'breakdown' => $breakdown,
                'total_remaining' => $totalRemaining,
                'next_expiration' => $nextExpiration,
            ];
        });
    }

    // --- MÉTODOS PRIVADOS DE AYUDA EXTREMADAMENTE REDUCIDOS ---

    public function applyBalanceAsPayment(Transaction $transaction, Customer $customer, float $amountToUse, int $sessionId, string $notes, Carbon $timestamp): void
    {
        $this->paymentService->processPayments($transaction, [[
            'amount' => $amountToUse,
            'method' => PaymentMethod::BALANCE->value,
            'notes' => $notes,
            'bank_account_id' => null,
        ]], $sessionId);
        $customer->useBalance($amountToUse, $transaction->id, $notes, $timestamp);
    }

    public function applyDirectPayments(Transaction $transaction, array $payments, int $sessionId): void
    {
        if (!empty($payments)) $this->paymentService->processPayments($transaction, $payments, $sessionId);
    }

    public function createTransactionItems(Transaction $transaction, array $cartItems, TransactionStatus $status): void {
        $branchId = $transaction->branch_id;
        $user = Auth::user() ?? $transaction->user;

        foreach ($cartItems as $item) {
            $itemableId = $item['product_attribute_id'] ?? $item['id'];
            $itemableType = !empty($item['product_attribute_id']) ? ProductAttribute::class : Product::class;
            $itemModel = $itemableType::find($itemableId);

            $itemDescription = $item['description'] ?? 'Artículo'; 
            if ($itemModel) {
                if ($itemModel instanceof ProductAttribute) {
                    $parentName = $itemModel->product ? $itemModel->product->name : 'Producto';
                    $itemDescription = $parentName . ' - ' . implode(' ', array_values($itemModel->attributes ?? []));
                } elseif ($itemModel instanceof Product) {
                    $itemDescription = $itemModel->name;
                }
            }

            $transaction->items()->create([
                'itemable_id' => $itemableId,
                'itemable_type' => $itemableType,
                'description' => $itemDescription,
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'discount_amount' => $item['discount'] ?? 0,
                'discount_reason' => $item['discount_reason'] ?? null,
                'line_total' => $item['quantity'] * $item['unit_price'],
            ]);

            if ($itemModel) {
                $isReservation = in_array($status, [TransactionStatus::ON_LAYAWAY, TransactionStatus::TO_DELIVER]);
                $description = $isReservation
                    ? "Reserva de apartados {$transaction->folio}"
                    : "Venta y baja de stock {$transaction->folio}";

                if ($isReservation) {
                    $itemModel->reserveStock($branchId, $item['quantity'], $user, $description);
                } else {
                    $itemModel->deductStock($branchId, $item['quantity'], $user, $description);
                }
            }
        }
    }

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