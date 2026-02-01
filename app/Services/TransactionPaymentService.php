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
use Illuminate\Database\Eloquent\Relations\Relation;

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
                'currency' => 'MXN',
                'status_changed_at' => $now,
                // GUARDAR FECHA DE VENCIMIENTO (Solo si viene en los datos)
                'layaway_expiration_date' => $validatedData['layaway_expiration_date'] ?? null,
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
     * Procesa un NUEVO PEDIDO (Order) desde el POS.
     * Crea una transacción con status TO_DELIVER y reserva stock.
     */
    public function handleNewOrder(User $user, array $data): Transaction
    {
        return DB::transaction(function () use ($user, $data) {
            $now = now();
            $sessionId = $data['cash_register_session_id'];

            // 1. Validar Cliente (Opcional si es guest)
            $customerId = $data['customer_id'] ?? null;

            // 2. Crear Transacción de Pedido
            $transaction = Transaction::create([
                'cash_register_session_id' => $sessionId,
                'folio' => self::generateFolio($user->branch_id),
                'customer_id' => $customerId,
                'contact_info' => $data['contact_info'] ?? null, // JSON para invitados
                'branch_id' => $user->branch_id,
                'user_id' => $user->id,
                'status' => TransactionStatus::TO_DELIVER, // <-- ESTATUS CLAVE
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

            // 3. Crear Items y Reservar Stock
            // Usamos TO_DELIVER para que createTransactionItems sepa que debe incrementar 'reserved_stock'
            $this->createTransactionItems($transaction, $data['cartItems'], TransactionStatus::TO_DELIVER);

            // NOTA: Por defecto, los pedidos nacen sin pagos (pago contra entrega).
            // Si en el futuro agregas pagos anticipados, aquí llamarías a $this->applyDirectPayments

            return $transaction;
        });
    }

    /**
     * Procesa un CAMBIO de producto (Exchange).
     * Devuelve items al inventario, saca nuevos items y ajusta la diferencia monetaria.
     */
    public function handleProductExchange(
        User $user,
        Transaction $originalTransaction,
        array $data
    ): Transaction {
        return DB::transaction(function () use ($user, $originalTransaction, $data) {
            $now = now();
            $sessionId = $data['cash_register_session_id'];
            $returnedItems = $data['returned_items'];
            $newCartItems = $data['new_items'];
            $payments = $data['payments'] ?? [];

            // Determinar Cliente
            $customerId = $data['new_customer_id'] ?? $originalTransaction->customer_id;
            $customer = $customerId ? Customer::find($customerId) : null;

            // guardar nuevo cliente a transaccion original si no tenia
            if ($customerId && !$originalTransaction->customer_id) {
                $originalTransaction->update(['customer_id' => $customerId]);
            }

            // 1. Procesar Devoluciones (Stock)
            $totalRefundValue = 0; // Valor comercial de lo devuelto
            foreach ($returnedItems as $returnItem) {
                $originalItem = TransactionItem::where('transaction_id', $originalTransaction->id)
                    ->where('id', $returnItem['item_id'])
                    ->firstOrFail();

                $refundValue = $originalItem->unit_price * $returnItem['quantity'];
                $totalRefundValue += $refundValue;

                // Restock
                $itemModel = $originalItem->itemable;
                if (!$itemModel && class_exists($originalItem->itemable_type)) {
                    $itemModel = $originalItem->itemable_type::find($originalItem->itemable_id);
                }
                if ($itemModel) $this->restockSingleItem($itemModel, $returnItem['quantity']);
            }

            // 2. Crear Nueva Transacción
            $newTransaction = Transaction::create([
                'cash_register_session_id' => $sessionId,
                'folio' => self::generateFolio($user->branch_id),
                'customer_id' => $customerId,
                'branch_id' => $user->branch_id,
                'user_id' => $user->id,
                'status' => TransactionStatus::COMPLETED,
                'channel' => TransactionChannel::POS,
                'subtotal' => $data['subtotal'],
                'total_discount' => $data['total_discount'] ?? 0,
                'total_tax' => 0,
                'currency' => 'MXN',
                'notes' => "Cambio de producto ref. Venta Original #{$originalTransaction->folio}. " . ($data['notes'] ?? ''),
                'status_changed_at' => $now,
            ]);

            // 3. Crear Items
            $this->createTransactionItems($newTransaction, $newCartItems, TransactionStatus::COMPLETED);

            // 4. Calcular Transferencia de Fondos
            // ¿Cuánto dinero real hay disponible de la venta original?
            $newTotalSale = (float) $newTransaction->total;
            $totalPaidOnOriginal = $originalTransaction->payments()->sum('amount');

            // Pago "virtual" por intercambio: cubre hasta el costo de la nueva venta o lo que se haya pagado
            $exchangePaymentAmount = min($newTotalSale, $totalPaidOnOriginal);

            if ($exchangePaymentAmount > 0) {
                $this->paymentService->processPayments($newTransaction, [[
                    'amount' => $exchangePaymentAmount,
                    'method' => PaymentMethod::EXCHANGE->value, // Asegúrate de tener este Enum o usa string 'intercambio'
                    'notes' => "Transferencia de pago desde venta #{$originalTransaction->folio}",
                    'bank_account_id' => null,
                ]], $sessionId);
            }

            // 5. Manejar Estatus Original y Cancelación de Deuda Antigua
            // Si la venta original tenía deuda, la "liberamos" devolviéndola al crédito
            if (!in_array($originalTransaction->status, [TransactionStatus::CANCELLED, TransactionStatus::REFUNDED])) {
                if ($customer && in_array($originalTransaction->status, [TransactionStatus::PENDING, TransactionStatus::ON_LAYAWAY])) {
                    $originalTotal = $originalTransaction->total;
                    $pendingAmount = $originalTotal - $totalPaidOnOriginal;

                    // "Cancelamos" la deuda antigua abonando al saldo
                    if ($pendingAmount > 0.01) {
                        $customer->increment('balance', $pendingAmount);
                        $customer->balanceMovements()->create([
                            'transaction_id' => $originalTransaction->id,
                            'type' => CustomerBalanceMovementType::CANCELLATION_CREDIT,
                            'amount' => $pendingAmount,
                            'balance_after' => $customer->balance,
                            'notes' => "Ajuste por cambio de estatus a 'Cambiado'. Deuda transferida/recalculada en nueva venta #{$newTransaction->folio}",
                            'created_at' => $now,
                        ]);
                    }
                }
                $originalTransactionStatus = $originalTransaction->status;
                $originalTransaction->update(['status' => TransactionStatus::CHANGED]);
            }

            // 6. Manejar Diferencia Financiera
            $remainingToPay = $newTotalSale - $exchangePaymentAmount;

            // --- NUEVO BLOQUE: APLICAR SALDO A FAVOR SI ES NECESARIO ---
            if ($remainingToPay > 0.01 && !empty($data['use_balance']) && $customer && $customer->balance > 0) {
                $balanceToUse = min($remainingToPay, (float) $customer->balance);
                
                if ($balanceToUse > 0) {
                    $this->applyBalanceAsPayment(
                        $newTransaction,
                        $customer,
                        $balanceToUse,
                        $sessionId,
                        "Uso de saldo en cambio #{$newTransaction->folio}",
                        $now
                    );
                    $remainingToPay -= $balanceToUse;
                }
            }

            // --- CASO 1: EL CLIENTE DEBE PAGAR MÁS O DEBE LO MISMO (NUEVO >= LO PAGADO) ---
            if ($remainingToPay > 0.01) {
                // Procesar pagos adicionales si los hubo
                if (!empty($payments)) {
                    $this->applyDirectPayments($newTransaction, $payments, $sessionId);
                    $totalPaidNew = $newTransaction->fresh()->payments()->sum('amount');
                    $remainingToPay = $newTotalSale - $totalPaidNew;
                }

                // Si aún falta dinero, se genera nueva deuda (o se usa crédito)
                if ($remainingToPay > 0.01) {
                    $useCredit = $data['use_credit_for_shortage'] ?? false;

                    // Lógica inteligente: Si no marcó "usar crédito" explícitamente, pero la venta original 
                    // YA ERA a crédito, asumimos que la deuda continúa.
                    if (!$useCredit && in_array($originalTransactionStatus, [TransactionStatus::PENDING, TransactionStatus::ON_LAYAWAY])) {
                        $useCredit = true;
                    }

                    if ($useCredit) {
                        if (!$customer) throw new Exception("No se puede dejar deuda a público general.");
                        // Validamos crédito disponible (recordando que ya "liberamos" la deuda anterior en el paso 5)
                        if ($remainingToPay > $customer->available_credit) throw new Exception("Crédito insuficiente para cubrir la diferencia.");

                        $this->applyDebtToCustomer(
                            $newTransaction,
                            $customer,
                            $remainingToPay,
                            CustomerBalanceMovementType::CREDIT_SALE,
                            "Saldo pendiente por cambio. Venta #{$newTransaction->folio}",
                            $now->copy()->addSecond()
                        );
                        $newTransaction->update(['status' => TransactionStatus::PENDING]);
                    } else {
                        throw new Exception("El monto cubierto no es suficiente y no se seleccionó crédito.");
                    }
                }

                // --- CASO 2: SOBRA DINERO REAL (PAGADO EN ORIG. > NUEVO TOTAL) ---
            } elseif ($newTotalSale < $totalPaidOnOriginal - 0.01) {
                $excessPayment = $totalPaidOnOriginal - $newTotalSale;

                // A) PAGAR OTRAS DEUDAS (MANDATORIO SI SE ENVÍAN)
                if (isset($data['debts_to_pay']) && is_array($data['debts_to_pay']) && $customer) {
                    foreach ($data['debts_to_pay'] as $debtToPay) {
                        if ($excessPayment <= 0.01) break;

                        $targetTxn = Transaction::find($debtToPay['id']);
                        
                        // Validaciones de seguridad para no pagar deudas ajenas
                        if (!$targetTxn || $targetTxn->customer_id !== $customer->id) continue;
                        
                        $realPending = $targetTxn->total - $targetTxn->payments()->sum('amount');
                        // Pagamos lo que diga el frontend, o lo pendiente real, o lo que nos quede de excedente
                        $amountToPay = min((float)$debtToPay['amount'], $realPending, $excessPayment);

                        if ($amountToPay > 0) {
                            $this->paymentService->processPayments($targetTxn, [[
                                'amount' => $amountToPay,
                                'method' => PaymentMethod::EXCHANGE->value,
                                'notes' => "Cubierto con excedente de cambio venta #{$newTransaction->folio}",
                                'bank_account_id' => null,
                            ]], $sessionId);

                            $newTotalPaidTarget = $targetTxn->fresh()->payments()->sum('amount');
                            if ($newTotalPaidTarget >= $targetTxn->total - 0.01) {
                                $targetTxn->update(['status' => TransactionStatus::COMPLETED]);
                                if ($targetTxn->status === TransactionStatus::ON_LAYAWAY) {
                                    $this->finalizeLayawayStock($targetTxn);
                                }
                            }

                            // --- CORRECCIÓN: ACTUALIZAR BALANCE Y CREAR MOVIMIENTO ---
                            // El pago reduce la deuda (o incrementa el "haber"), por lo tanto incrementamos el balance.
                            $customer->increment('balance', $amountToPay);

                            $customer->balanceMovements()->create([
                                'transaction_id' => $targetTxn->id,
                                'type' => CustomerBalanceMovementType::PAYMENT, // "Abono"
                                'amount' => $amountToPay,
                                'balance_after' => $customer->balance,
                                'notes' => "Abono liquidado con excedente de cambio #{$newTransaction->folio}",
                                'created_at' => $now->copy()->addSecond(),
                                'updated_at' => $now->copy()->addSecond(),
                            ]);

                            $excessPayment -= $amountToPay;
                        }
                    }
                }

                // B) SI AÚN SOBRA DINERO -> SALDO A FAVOR O EFECTIVO
                if ($excessPayment > 0.01) {
                    $refundType = $data['exchange_refund_type'] ?? 'balance';

                    if ($refundType === 'balance') {
                        if (!$customer) throw new Exception("Error lógico: Se intentó abonar a saldo sin cliente.");

                        $customer->increment('balance', $excessPayment);
                        $customer->balanceMovements()->create([
                            'transaction_id' => $newTransaction->id,
                            'type' => CustomerBalanceMovementType::REFUND_CREDIT,
                            'amount' => $excessPayment,
                            'balance_after' => $customer->balance,
                            'notes' => "Saldo a favor restante por cambio (Excedente). Venta #{$newTransaction->folio}",
                            'created_at' => $now->copy()->addSecond(),
                        ]);
                    } else {
                        // Devolución en efectivo (Caja)
                        $session = $user->cashRegisterSessions()->find($sessionId);
                        if ($session) {
                            $session->cashMovements()->create([
                                'user_id' => $user->id,
                                'type' => SessionCashMovementType::OUTFLOW,
                                'amount' => $excessPayment,
                                'description' => "Devolución efectivo cambio #{$newTransaction->folio}",
                                'notes' => "Diferencia a favor entregada al cliente.",
                            ]);
                        }
                    }
                }
            }

            return $newTransaction;
        });
    }

    /**
     * Procesa específicamente cambios en un APARTADO (ON_LAYAWAY).
     * Mantiene la lógica de stock reservado y transferencia de abonos.
     */
    public function handleLayawayExchange(
        User $user,
        Transaction $originalTransaction,
        array $data
    ): Transaction {
        return DB::transaction(function () use ($user, $originalTransaction, $data) {
            $now = now();
            $sessionId = $data['cash_register_session_id'];
            $returnedItems = $data['returned_items'];
            $newCartItems = $data['new_items'];
            $payments = $data['payments'] ?? [];
            
            if ($originalTransaction->status !== TransactionStatus::ON_LAYAWAY) {
                throw new Exception("Esta función es exclusiva para transacciones en estatus de Apartado.");
            }

            // Cliente (siempre debe existir en un apartado)
            $customerId = $data['new_customer_id'] ?? $originalTransaction->customer_id;
            $customer = Customer::find($customerId);
            if (!$customer) throw new Exception("Se requiere un cliente válido para operaciones de apartado.");

            // 1. Procesar Devoluciones (Liberar Reserva)
            foreach ($returnedItems as $returnItem) {
                $originalItem = TransactionItem::where('transaction_id', $originalTransaction->id)
                    ->where('id', $returnItem['item_id'])
                    ->firstOrFail();

                // Recuperar Modelo
                $itemModel = $originalItem->itemable;
                if (!$itemModel && class_exists($originalItem->itemable_type)) {
                    $itemModel = $originalItem->itemable_type::find($originalItem->itemable_id);
                }
                
                // CRÍTICO: En un apartado, devolver significa liberar la reserva.
                // No tocamos current_stock porque nunca salió físicamente.
                if ($itemModel) {
                    $itemModel->decrement('reserved_stock', $returnItem['quantity']);
                    if ($itemModel instanceof ProductAttribute) {
                        $itemModel->product->decrement('reserved_stock', $returnItem['quantity']);
                    }
                }
            }

            // 2. Crear Nueva Transacción (Inicialmente como Apartado)
            $newTransaction = Transaction::create([
                'cash_register_session_id' => $sessionId,
                'folio' => self::generateFolio($user->branch_id),
                'customer_id' => $customerId,
                'branch_id' => $user->branch_id,
                'user_id' => $user->id,
                'status' => TransactionStatus::ON_LAYAWAY, // Nace como apartado
                'channel' => TransactionChannel::POS,
                'subtotal' => $data['subtotal'],
                'total_discount' => $data['total_discount'] ?? 0,
                'total_tax' => 0,
                'currency' => 'MXN',
                'notes' => "Modificación de apartado. Ref. Original #{$originalTransaction->folio}. " . ($data['notes'] ?? ''),
                'status_changed_at' => $now,
                'layaway_expiration_date' => $originalTransaction->layaway_expiration_date, // Heredar vencimiento
            ]);

            // 3. Crear Nuevos Items (Reservar Stock)
            // Usamos TransactionStatus::ON_LAYAWAY para que el helper sepa que debe incrementar 'reserved_stock'
            $this->createTransactionItems($newTransaction, $newCartItems, TransactionStatus::ON_LAYAWAY);

            // 4. Transferir Abonos Previos
            // El dinero que el cliente ya dio, se mueve a la nueva nota.
            $previousPaymentsTotal = $originalTransaction->payments()->sum('amount');
            
            if ($previousPaymentsTotal > 0) {
                 $this->paymentService->processPayments($newTransaction, [[
                    'amount' => $previousPaymentsTotal,
                    'method' => PaymentMethod::EXCHANGE->value, // O un método "TRANSFERENCIA_APARTADO"
                    'notes' => "Transferencia de abonos del apartado #{$originalTransaction->folio}",
                    'bank_account_id' => null,
                ]], $sessionId);
            }

            // 5. Marcar Original como Cambiada (Cerrarla)
            $originalTransaction->update(['status' => TransactionStatus::CHANGED]);

            // 6. Calcular Estado Financiero
            $newTotal = $newTransaction->total;
            $currentPaid = $previousPaymentsTotal; // Hasta ahora solo tiene lo transferido
            
            // ¿Se agregaron nuevos pagos en esta operación?
            $additionalPaymentsTotal = 0;
            if (!empty($payments)) {
                $this->applyDirectPayments($newTransaction, $payments, $sessionId);
                $additionalPaymentsTotal = collect($payments)->sum('amount');
            }
            
            $totalPaidFinal = $currentPaid + $additionalPaymentsTotal;
            $remainingBalance = $newTotal - $totalPaidFinal;

            // A) Si ya se pagó todo (o más)
            if ($remainingBalance <= 0.01) {
                // Cambiar a COMPLETADO
                $newTransaction->update(['status' => TransactionStatus::COMPLETED]);
                
                // CRÍTICO: Al completarse un apartado, el stock pasa de "Reservado" a "Vendido" (baja físico).
                $this->finalizeLayawayStock($newTransaction);

                // Manejar Excedente (Saldo a Favor)
                if ($remainingBalance < -0.01) {
                    $excess = abs($remainingBalance);
                    
                    // Abonar a saldo del cliente
                    $customer->increment('balance', $excess);
                    $customer->balanceMovements()->create([
                        'transaction_id' => $newTransaction->id,
                        'type' => CustomerBalanceMovementType::REFUND_CREDIT,
                        'amount' => $excess,
                        'balance_after' => $customer->balance,
                        'notes' => "Saldo a favor por modificación de apartado #{$newTransaction->folio} (Excedente)",
                        'created_at' => $now->copy()->addSecond(),
                    ]);
                }
            } 
            // B) Si aún debe dinero
            else {
                // Se queda como ON_LAYAWAY (ya seteado al crear).
                // Generar movimiento de deuda "virtual" o actualizar saldo
                // En tu lógica actual de 'handleNewSale', si hay deuda en apartado, se registra el movimiento negativo en el cliente?
                // Revisando handleNewSale: Sí, llama a applyDebtToCustomer.
                
                // Sin embargo, en un sistema de Apartados, a veces la deuda no se refleja en el saldo global hasta que se vence,
                // O se refleja como "Saldo Apartado". Dependerá de tu Enum CustomerBalanceMovementType.
                // Asumiremos que debemos registrar el ajuste de la deuda si cambió el monto total.
                
                // NOTA: Para simplificar y no duplicar deuda, lo ideal es recalcular la deuda total.
                // Si en el original ya había una deuda registrada de (TotalOriginal - PagadoOriginal),
                // Ahora debemos ajustar esa deuda.
                
                // La forma más limpia en historial:
                // 1. "Cancelar" la deuda del apartado anterior (Paso implícito si consideramos que cerramos la transaccion).
                //    Pero como handleNewSale registra deuda al inicio, aquí deberíamos registrar la nueva deuda.
                
                // Vamos a registrar la nueva deuda total remanente.
                // Pero antes, debimos haber "cancelado" la deuda anterior si existía.
                // En handleProductExchange hacemos: "Cancelamos la deuda antigua abonando al saldo".
                
                // 6.1 Cancelar deuda anterior (si la hubo)
                $originalTotal = $originalTransaction->total;
                $originalDebt = $originalTotal - $previousPaymentsTotal;
                
                if ($originalDebt > 0.01) {
                    $customer->increment('balance', $originalDebt);
                    $customer->balanceMovements()->create([
                        'transaction_id' => $originalTransaction->id,
                        'type' => CustomerBalanceMovementType::CANCELLATION_CREDIT,
                        'amount' => $originalDebt,
                        'balance_after' => $customer->balance,
                        'notes' => "Cancelación deuda apartado anterior #{$originalTransaction->folio} por modificación",
                        'created_at' => $now,
                    ]);
                }
                
                // 6.2 Registrar nueva deuda
                $debtType = defined('App\Enums\CustomerBalanceMovementType::LAYAWAY_DEBT') 
                    ? CustomerBalanceMovementType::LAYAWAY_DEBT 
                    : CustomerBalanceMovementType::CREDIT_SALE;

                $this->applyDebtToCustomer(
                    $newTransaction,
                    $customer,
                    $remainingBalance,
                    $debtType,
                    "Saldo pendiente por modificación apartado #{$newTransaction->folio}",
                    $now->copy()->addSecond()
                );
            }

            return $newTransaction;
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
     * MEJORA: Crea los items y asegura actualización de stock consistente para variantes y padres.
     */
    private function createTransactionItems(Transaction $transaction, array $cartItems, TransactionStatus $status): void
    {
        foreach ($cartItems as $item) {
            $itemableId = $item['id'];
            $itemableType = Product::class;
            // Se buscará el modelo más adelante, dependiendo de si es variante o no.

            if (!empty($item['product_attribute_id'])) {
                $itemableId = $item['product_attribute_id'];
                $itemableType = ProductAttribute::class;
                $itemModel = ProductAttribute::find($item['product_attribute_id']);
            } else {
                $itemModel = Product::find($item['id']);
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
            if ($itemModel) {
                if ($status === TransactionStatus::ON_LAYAWAY || $status === TransactionStatus::TO_DELIVER) {
                    // Apartado
                    $itemModel->increment('reserved_stock', $item['quantity']);
                    if ($itemModel instanceof ProductAttribute) {
                        // USAR LA RELACIÓN REAL PARA ENCONTRAR AL PADRE
                        Product::find($itemModel->product_id)->increment('reserved_stock', $item['quantity']);
                    }
                } else {
                    // Venta directa
                    $itemModel->decrement('current_stock', $item['quantity']);
                    if ($itemModel instanceof ProductAttribute) {
                        // USAR LA RELACIÓN REAL PARA ENCONTRAR AL PADRE
                        Product::find($itemModel->product_id)->decrement('current_stock', $item['quantity']);
                    }
                }
            }
        }
    }

    /**
     * Helper para reponer stock de un item específico (usado en cambios).
     */
    private function restockSingleItem($itemModel, $quantity): void
    {
        if ($itemModel instanceof Product || $itemModel instanceof ProductAttribute) {
            $itemModel->increment('current_stock', $quantity);
            // Si es variante, incrementar también al padre si aplica lógica de stock compartido
            if ($itemModel instanceof ProductAttribute) {
                Product::find($itemModel->product_id)->increment('current_stock', $quantity);
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