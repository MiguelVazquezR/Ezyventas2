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

/**
 * Servicio para orquestar operaciones de pago complejas que
 * involucran transacciones, clientes y movimientos de saldo.
 */
class TransactionPaymentService
{
    /**
     * @param PaymentService $paymentService El servicio de bajo nivel para registrar pagos.
     */
    public function __construct(protected PaymentService $paymentService) {}

    /**
     * Procesa una nueva venta (Contado, Crédito o Apartado) desde el POS.
     * Encapsula la lógica de checkout() y createLayaway() de PointOfSaleController.
     *
     * @param array $validatedData Datos validados del request.
     * @param User $user El usuario que realiza la operación.
     * @param Customer|null $customer El cliente asociado (si existe).
     * @param TransactionStatus $initialStatus El estado inicial (PENDING, ON_LAYAWAY).
     * @param CustomerBalanceMovementType|null $debtType El tipo de movimiento si se genera deuda (CREDIT_SALE, LAYAWAY_DEBT).
     * @return Transaction La transacción creada y procesada.
     */
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

            // 1. Crear la Transacción
            $transaction = Transaction::create([
                'cash_register_session_id' => $sessionId,
                'folio' => self::generateFolio($user->branch_id),
                'customer_id' => $customer?->id,
                'branch_id' => $user->branch_id,
                'user_id' => $user->id,
                'status' => $initialStatus,
                'channel' => TransactionChannel::POS,
                'subtotal' => $validatedData['subtotal'],
                'total_discount' => $validatedData['total_discount'] ?? 0,
                'total_tax' => 0,
                // 'total' => $totalSale,
                'currency' => 'MXN',
                'status_changed_at' => $now,
            ]);

            // 2. Crear Items y manejar Stock
            $this->createTransactionItems($transaction, $validatedData['cartItems'], $initialStatus);

            $balanceToUse = 0;

            // 3. Aplicar Saldo a Favor (si se usa)
            if ($validatedData['use_balance'] && $customer && $customer->balance > 0) {
                $balanceToUse = min($totalSale, (float) $customer->balance);
                if ($balanceToUse > 0) {
                    $this->applyBalanceAsPayment(
                        $transaction,
                        $customer,
                        $balanceToUse,
                        $sessionId,
                        "Uso de saldo en venta POS #{$transaction->folio}",
                        $now
                    );
                }
            }

            // 4. Aplicar Pagos Directos
            $totalDue = $totalSale - $balanceToUse;
            if (!empty($paymentsFromRequest)) {
                $paymentsToProcess = $this->capPaymentsToAmount($paymentsFromRequest, $totalDue);
                $this->applyDirectPayments(
                    $transaction,
                    $paymentsToProcess,
                    $sessionId
                );
            }

            // 5. Calcular estado final y gestionar deuda
            $totalPaid = $transaction->fresh()->payments()->sum('amount');
            $remainingDue = $totalSale - $totalPaid;

            if ($remainingDue > 0.01) {
                // Venta a Crédito o Apartado con deuda
                if (!$customer || ($debtType === CustomerBalanceMovementType::CREDIT_SALE && $remainingDue > $customer->available_credit)) {
                    throw new Exception("Pago insuficiente y el cliente no tiene crédito disponible.");
                }

                $this->applyDebtToCustomer(
                    $transaction,
                    $customer,
                    $remainingDue,
                    $debtType,
                    "Cargo a saldo por venta #{$transaction->folio}",
                    $now->copy()->addSecond()
                );
            } else {
                // Venta Pagada por Completo
                $transaction->update(['status' => TransactionStatus::COMPLETED]);

                // Si era un apartado que se pagó completo de inicio, mover stock
                if ($initialStatus === TransactionStatus::ON_LAYAWAY) {
                    $this->finalizeLayawayStock($transaction);
                }
            }

            return $transaction;
        });
    }

    /**
     * Aplica un pago a una transacción existente (ej. Orden de Servicio).
     * Encapsula la lógica de PaymentController@store.
     *
     * @param Transaction $transaction La transacción (Orden de Servicio) a la que se abona.
     * @param array $validatedData Datos validados del request.
     * @param int $sessionId ID de la sesión de caja.
     * @return void
     */
    public function applyPaymentToTransaction(Transaction $transaction, array $validatedData, int $sessionId): void
    {
        DB::transaction(function () use ($transaction, $validatedData, $sessionId) {
            $customer = $transaction->customer;
            $now = now();

            // 1. Calcular deuda
            $totalPaidOnTransaction = $transaction->payments()->sum('amount');
            $remainingDue = $transaction->total - $totalPaidOnTransaction;

            if ($remainingDue <= 0.01) {
                throw new Exception('Esta transacción ya está completamente pagada.');
            }

            $balanceToUse = 0;
            $totalFromPayments = 0;

            // 2. Calcular abono con Saldo a Favor
            if (!empty($validatedData['use_balance']) && $customer && $customer->balance > 0) {
                $balanceToUse = min($customer->balance, $remainingDue);
            }

            // 3. Calcular abono con Pagos Directos
            if (!empty($validatedData['payments'])) {
                $totalFromPayments = array_sum(array_column($validatedData['payments'], 'amount'));
            }

            $totalAmountToPay = $balanceToUse + $totalFromPayments;

            // 4. Validar sobrepago
            if ($totalAmountToPay > $remainingDue + 0.01) {
                throw new Exception('El monto total del pago excede el saldo pendiente de la transacción.');
            }

            // 5. Aplicar pago con Saldo a Favor
            if ($balanceToUse > 0) {
                $this->applyBalanceAsPayment(
                    $transaction,
                    $customer,
                    $balanceToUse,
                    $sessionId,
                    "Uso de saldo a favor en abono a O.S. #{$transaction->folio}",
                    $now
                );
            }

            // 6. Aplicar Pagos Directos (y reducir deuda del cliente)
            if ($totalFromPayments > 0) {
                $paymentsFromRequest = $validatedData['payments'];

                // Registra los pagos
                $this->applyDirectPayments($transaction, $paymentsFromRequest, $sessionId);

                // Si hay cliente, se reduce su deuda (incrementa balance)
                if ($customer) {
                    $customer->increment('balance', $totalFromPayments);
                    $customer->balanceMovements()->create([
                        'transaction_id' => $transaction->id,
                        'type' => CustomerBalanceMovementType::PAYMENT,
                        'amount' => $totalFromPayments, // Positivo (abono)
                        'balance_after' => $customer->balance,
                        'notes' => "Abono a O.S. #{$transaction->folio}",
                        'created_at' => $now->copy()->addSecond(),
                        'updated_at' => $now->copy()->addSecond(),
                    ]);
                }
            }

            // 7. Actualizar estado de la transacción (movido de PaymentService)
            $totalPaid = $transaction->fresh()->payments()->sum('amount');
            if ($totalPaid >= $transaction->total - 0.01) {
                $transaction->update(['status' => TransactionStatus::COMPLETED]);
            }
        });
    }

    /**
     * Aplica un pago general al saldo de un cliente, cubriendo deudas (FIFO).
     * Encapsula la lógica de CustomerPaymentController@store.
     *
     * @param Customer $customer El cliente que paga.
     * @param array $validatedData Datos validados del request.
     * @param int $sessionId ID de la sesión de caja.
     * @param User $user El usuario que registra el pago.
     * @return void
     */
    public function applyPaymentToCustomerBalance(Customer $customer, array $validatedData, int $sessionId, User $user): void
    {
        DB::transaction(function () use ($customer, $validatedData, $sessionId, $user) {
            $now = now();
            $balanceMovementsToCreate = [];

            // 1. Buscar *TODAS* las deudas
            $pendingTransactions = $customer->transactions()
                ->whereIn('status', [TransactionStatus::PENDING, TransactionStatus::ON_LAYAWAY])
                ->orderBy('created_at', 'asc') // FIFO
                ->get();

            foreach ($validatedData['payments'] as $paymentData) {
                $amountToApply = (float) $paymentData['amount'];

                // 2. Aplicar a deudas pendientes (FIFO)
                foreach ($pendingTransactions as $transaction) {
                    if ($amountToApply <= 0.001) break;

                    $originalStatus = $transaction->status;
                    $totalPaidOnTransaction = $transaction->payments()->sum('amount');
                    $pendingAmountOnTransaction = $transaction->total - $totalPaidOnTransaction;

                    if ($pendingAmountOnTransaction <= 0.001) continue;

                    $amountForThisTransaction = min($amountToApply, $pendingAmountOnTransaction);

                    // --- ¡AQUÍ ESTÁ LA CORRECCIÓN! ---
                    $this->paymentService->processPayments(
                        $transaction, // <-- DEBE SER $transaction
                        [[
                            'amount' => $amountForThisTransaction, // <-- DEBE SER $amountForThisTransaction
                            'method' => $paymentData['method'],
                            'notes' => 'Abono a deuda. ' . ($validatedData['notes'] ?? ''), // Nota corregida
                            'bank_account_id' => $paymentData['bank_account_id'] ?? null,
                        ]],
                        $sessionId
                    );
                    // --- FIN DE LA CORRECCIÓN ---

                    // 3. Comprobar si se liquidó
                    $newTotalPaid = $totalPaidOnTransaction + $amountForThisTransaction;
                    if ($newTotalPaid >= $transaction->total - 0.01) {
                        $transaction->update(['status' => TransactionStatus::COMPLETED]);
                        if ($originalStatus === TransactionStatus::ON_LAYAWAY) {
                            $this->finalizeLayawayStock($transaction);
                        }
                    }

                    $customer->increment('balance', $amountForThisTransaction);

                    // Guardar movimiento de saldo para creación posterior
                    $balanceMovementsToCreate[] = [
                        'transaction_id' => $transaction->id,
                        'type' => CustomerBalanceMovementType::PAYMENT,
                        'amount' => $amountForThisTransaction,
                        'balance_after' => $customer->balance,
                        'notes' => "Abono a la venta #{$transaction->folio} (" . $paymentData['method'] . "). " . ($validatedData['notes'] ?? ''),
                        'timestamp' => $now,
                    ];

                    $amountToApply -= $amountForThisTransaction;
                }

                // 3. Aplicar restante como Saldo a Favor
                if ($amountToApply > 0.001) {
                    $balanceTransaction = $this->createBalancePaymentTransaction($customer, $user, $sessionId, $amountToApply, $now);

                    // Esta llamada (que estaba copiada arriba) SÍ es correcta aquí
                    $this->paymentService->processPayments(
                        $balanceTransaction,
                        [[
                            'amount' => $amountToApply,
                            'method' => $paymentData['method'],
                            'notes' => 'Abono directo a saldo. ' . ($validatedData['notes'] ?? ''),
                            'bank_account_id' => $paymentData['bank_account_id'] ?? null,
                        ]],
                        $sessionId
                    );

                    $customer->increment('balance', $amountToApply);

                    $balanceMovementsToCreate[] = [
                        'transaction_id' => $balanceTransaction->id,
                        'type' => CustomerBalanceMovementType::PAYMENT,
                        'amount' => $amountToApply,
                        'balance_after' => $customer->balance,
                        'notes' => 'Abono a saldo a favor. ' . ($validatedData['notes'] ?? ''),
                        'timestamp' => $now,
                    ];
                }
            }

            // 4. Crear movimientos de saldo
            $this->createStaggeredBalanceMovements($customer, $balanceMovementsToCreate, $now);
        });
    }

    // --- MÉTODOS PRIVADOS DE AYUDA (El núcleo de la centralización) ---

    /**
     * Aplica el Saldo a Favor del cliente como un pago a la transacción.
     */
    private function applyBalanceAsPayment(
        Transaction $transaction,
        Customer $customer,
        float $amountToUse,
        int $sessionId,
        string $notes,
        Carbon $timestamp
    ): void {
        $balancePaymentData = [[
            'amount' => $amountToUse,
            'method' => PaymentMethod::BALANCE->value,
            'notes' => $notes,
            'bank_account_id' => null,
        ]];

        $this->paymentService->processPayments($transaction, $balancePaymentData, $sessionId);

        $customer->decrement('balance', $amountToUse);

        $customer->balanceMovements()->create([
            'transaction_id' => $transaction->id,
            'type' => CustomerBalanceMovementType::CREDIT_USAGE,
            'amount' => -$amountToUse, // Negativo (uso de saldo)
            'balance_after' => $customer->balance,
            'notes' => $notes,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    /**
     * Aplica los pagos directos (efectivo, tarjeta) a la transacción.
     */
    private function applyDirectPayments(Transaction $transaction, array $payments, int $sessionId): void
    {
        if (!empty($payments)) {
            $this->paymentService->processPayments($transaction, $payments, $sessionId);
        }
    }

    /**
     * Aplica una deuda (saldo negativo) al cliente.
     */
    private function applyDebtToCustomer(
        Transaction $transaction,
        Customer $customer,
        float $debtAmount,
        CustomerBalanceMovementType $debtType,
        string $notes,
        Carbon $timestamp
    ): void {
        $customer->decrement('balance', $debtAmount);

        $customer->balanceMovements()->create([
            'transaction_id' => $transaction->id,
            'type' => $debtType,
            'amount' => -$debtAmount, // Negativo (deuda)
            'balance_after' => $customer->balance,
            'notes' => $notes,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    /**
     * Crea los items de la transacción y afecta el stock según el tipo de venta.
     */
    private function createTransactionItems(Transaction $transaction, array $cartItems, TransactionStatus $status): void
    {
        foreach ($cartItems as $item) {
            $itemableId = $item['id'];
            $itemableType = Product::class;
            $itemModel = Product::find($item['id']);

            if (!empty($item['product_attribute_id'])) {
                $itemableId = $item['product_attribute_id'];
                $itemableType = ProductAttribute::class;
                $itemModel = ProductAttribute::find($item['product_attribute_id']);
            }

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

            // Lógica de Stock
            if ($status === TransactionStatus::ON_LAYAWAY) {
                // Apartado: Mover de 'disponible' a 'reservado'
                $itemModel->increment('reserved_stock', $item['quantity']);
                if ($itemModel instanceof ProductAttribute) {
                    Product::find($item['id'])->increment('reserved_stock', $item['quantity']);
                }
            } else {
                // Venta de Contado/Crédito: Descontar de 'disponible'
                $itemModel->decrement('current_stock', $item['quantity']);
                if ($itemModel instanceof ProductAttribute) {
                    Product::find($item['id'])->decrement('current_stock', $item['quantity']);
                }
            }
        }
    }

    /**
     * Mueve el stock de 'reservado' a 'vendido' cuando un apartado se liquida.
     */
    private function finalizeLayawayStock(Transaction $transaction): void
    {
        foreach ($transaction->items as $txnItem) {
            $itemModel = $txnItem->itemable; // Esto es Product o ProductAttribute

            // 1. Si el itemable (producto o variante) ya no existe,
            //    no podemos hacer nada. Simplemente saltamos.
            if (!$itemModel) {
                Log::warning("No se pudo finalizar el stock para el item {$txnItem->id} de la transacción {$transaction->id}: El producto/variante ya no existe.");
                continue;
            }

            // 2. Decrementar el stock del itemable (sea Producto o Atributo)
            //    Esto mueve de 'reservado' a 0 y de 'físico' a -1.
            $itemModel->decrement('reserved_stock', $txnItem->quantity);
            $itemModel->decrement('current_stock', $txnItem->quantity);

            // 3. Si el itemable era una VARIANTE (ProductAttribute)...
            if ($itemModel instanceof \App\Models\ProductAttribute) {
                // ...también debemos actualizar las cantidades del PRODUCTO PADRE.
                $product = $itemModel->product; // Asumiendo que tienes esta relación
                
                if ($product) {
                    $product->decrement('reserved_stock', $txnItem->quantity);
                    $product->decrement('current_stock', $txnItem->quantity);
                } else {
                    Log::warning("La variante {$itemModel->id} no tiene un producto padre asociado.");
                }
            }
            // 4. Si era un Producto simple, no se necesita hacer nada más,
            //    ya que el paso 2 descontó el stock del producto principal.
        }
    }

    /**
     * Limita un array de pagos a un monto máximo total.
     */
    private function capPaymentsToAmount(array $payments, float $maxAmount): array
    {
        $totalPaid = collect($payments)->sum('amount');
        if ($totalPaid <= $maxAmount) {
            return $payments;
        }

        $cappedPayments = [];
        $runningTotal = 0;
        foreach ($payments as $payment) {
            $paymentAmount = (float) $payment['amount'];
            $amountToCap = $maxAmount - $runningTotal;

            if ($amountToCap <= 0) break;

            $amountToRecord = min($paymentAmount, $amountToCap);
            $cappedPayments[] = array_merge($payment, ['amount' => $amountToRecord]);
            $runningTotal += $amountToRecord;
        }
        return $cappedPayments;
    }

    /**
     * Crea una transacción de "Abono a Saldo" para registrar ingresos que no van a deudas.
     */
    private function createBalancePaymentTransaction(Customer $customer, User $user, int $sessionId, float $amount, Carbon $timestamp): Transaction
    {
        return $customer->transactions()->create([
            'folio' => self::generateBalancePaymentFolio($user->branch_id),
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'cash_register_session_id' => $sessionId,
            'subtotal' => $amount,
            'total_discount' => 0,
            'total_tax' => 0,
            'channel' => TransactionChannel::BALANCE_PAYMENT,
            'status' => TransactionStatus::COMPLETED,
            'notes' => 'Transacción generada para registrar abono a saldo a favor.',
            'created_at' => $timestamp, // Asegurar consistencia
            'updated_at' => $timestamp,
        ]);
    }

    /**
     * Crea múltiples movimientos de saldo asegurando que tengan timestamps únicos para ordenamiento.
     */
    private function createStaggeredBalanceMovements(Customer $customer, array $movements, Carbon $baseTimestamp): void
    {
        $movementsCount = count($movements);
        if ($movementsCount === 0) return;

        for ($i = 0; $i < $movementsCount; $i++) {
            $timestamp = ($movementsCount > 1) ? $baseTimestamp->copy()->addSeconds($i) : $baseTimestamp;

            $customer->balanceMovements()->create([
                'transaction_id' => $movements[$i]['transaction_id'],
                'type' => $movements[$i]['type'],
                'amount' => $movements[$i]['amount'],
                'balance_after' => $movements[$i]['balance_after'], // El balance ya está calculado secuencialmente
                'notes' => $movements[$i]['notes'],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }

    // --- GENERADORES DE FOLIO (Centralizados) ---

    public static function generateFolio(int $branchId): string
    {
        $lastTransaction = Transaction::where('branch_id', $branchId)
            ->where('folio', 'LIKE', 'V-%')
            ->orderBy('id', 'desc')
            ->first();

        $sequence = $lastTransaction ? ((int) substr($lastTransaction->folio, 2)) + 1 : 1;
        return 'V-' . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }

    public static function generateBalancePaymentFolio(int $branchId): string
    {
        $lastTransaction = Transaction::where('branch_id', $branchId)
            ->where('folio', 'like', 'ABONO-%')
            ->orderByRaw('CAST(SUBSTRING(folio, 7) AS UNSIGNED) DESC')
            ->first();

        $sequence = $lastTransaction ? ((int) substr($lastTransaction->folio, 6)) + 1 : 1;
        return 'ABONO-' . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }
}
