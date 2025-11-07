<?php

namespace App\Http\Controllers;

// Imports necesarios para la lógica
use App\Enums\CustomerBalanceMovementType;
use App\Enums\PaymentMethod;
use App\Models\Transaction;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Exception;

class PaymentController extends Controller
{
    /**
     * Almacena nuevos pagos (abonos) para una transacción existente,
     * aceptando pagos directos y/o uso de saldo a favor.
     *
     * @param Request $request La solicitud HTTP con los datos del pago.
     * @param Transaction $transaction Elocuent inyecta la transacción (Orden de Servicio) a la que se abonará.
     * @param PaymentService $paymentService El servicio que procesa y registra pagos.
     */
    public function store(Request $request, Transaction $transaction, PaymentService $paymentService)
    {
        // 1. VALIDACIÓN DEL PAYLOAD
        // Validamos la entrada para que coincida con lo que envía MultiPaymentProcessor
        $validated = $request->validate([
            // Requerimos el ID de la sesión de caja, que ServiceOrderShow.vue debe inyectar.
            'cash_register_session_id' => 'required|exists:cash_register_sessions,id,status,abierta',
            
            // 'use_balance' es un booleano simple.
            'use_balance' => 'required|boolean',
            
            // 'payments' ahora es 'nullable' porque el pago podría ser *solo* con saldo a favor.
            'payments' => 'nullable|array',
            'payments.*.amount' => 'required_with:payments|numeric|min:0.01',
            'payments.*.method' => ['required_with:payments', Rule::in(['efectivo', 'tarjeta', 'transferencia'])],
            'payments.*.notes' => 'nullable|string',
            'payments.*.bank_account_id' => [
                'nullable',
                'integer',
                'exists:bank_accounts,id',
                // Es requerido si uno de los métodos de pago es tarjeta o transferencia.
                Rule::requiredIf(function () use ($request) {
                    foreach ($request->input('payments', []) as $payment) {
                        if (in_array($payment['method'], ['tarjeta', 'transferencia'])) {
                            return true;
                        }
                    }
                    return false;
                }),
            ],
        ], [
            'cash_register_session_id.required' => 'No se ha proporcionado una sesión de caja activa.'
        ]);

        // Validación personalizada: Asegurarse de que al menos un método de pago venga.
        if (empty($validated['payments']) && !$validated['use_balance']) {
            throw ValidationException::withMessages([
                'payments' => 'Debe proporcionar al menos un método de pago o usar el saldo a favor.',
            ]);
        }


        try {
            // 2. INICIO DE LA TRANSACCIÓN DE BASE DE DATOS
            // Usamos una transacción para asegurar que todas las operaciones (pagos, movimientos de saldo)
            // se completen con éxito, o se reviertan todas en caso de error.
            DB::transaction(function () use ($validated, $transaction, $paymentService) {
                
                // 3. OBTENCIÓN DE DATOS INICIALES
                $customer = $transaction->customer;
                $sessionId = $validated['cash_register_session_id'];
                $now = now(); // Timestamp consistente para todos los registros

                // 4. CÁLCULO DE MONTOS
                
                // Calcular cuánto falta por pagar en esta orden ANTES de este abono.
                $totalPaidOnTransaction = $transaction->payments()->sum('amount');
                $remainingDue = $transaction->total - $totalPaidOnTransaction;

                // Si por alguna razón la deuda es 0 o negativa, no hay nada que pagar.
                if ($remainingDue <= 0.01) {
                    throw new Exception('Esta transacción ya está completamente pagada.');
                }

                $balanceToUse = 0;
                $totalFromPayments = 0;

                // Calcular cuánto se usará de saldo a favor
                if (!empty($validated['use_balance']) && $customer && $customer->balance > 0) {
                    // El monto a usar es el mínimo entre el saldo del cliente y la deuda de la orden.
                    $balanceToUse = min($customer->balance, $remainingDue);
                }

                // Calcular cuánto se pagará con métodos directos (efectivo, tarjeta, etc.)
                if (!empty($validated['payments'])) {
                    $totalFromPayments = array_sum(array_column($validated['payments'], 'amount'));
                }

                // Sumar ambos para obtener el total de este abono.
                $totalAmountToPay = $balanceToUse + $totalFromPayments;

                // 5. VALIDACIÓN DE SOBREPAGO
                // Comprobamos que el abono total no sea mayor que la deuda restante.
                // (Se añade 0.01 por seguridad con los decimales)
                if ($totalAmountToPay > $remainingDue + 0.01) {
                    throw new Exception('El monto total del pago excede el saldo pendiente de la transacción.');
                }

                // 6. PROCESAR PAGO CON SALDO (SI APLICA)
                if ($balanceToUse > 0) {
                    // Preparamos el payload para el PaymentService
                    $balancePaymentData = [[
                        'amount' => $balanceToUse,
                        'method' => PaymentMethod::BALANCE->value,
                        'notes' => "Uso de saldo a favor en abono a O.S. #{$transaction->folio}",
                        'bank_account_id' => null,
                    ]];

                    // Registramos el pago (esto actualiza el 'total pagado' de la transacción)
                    $paymentService->processPayments($transaction, $balancePaymentData, $sessionId);

                    // Disminuimos el saldo a favor del cliente
                    $customer->decrement('balance', $balanceToUse);

                    // Creamos el registro del movimiento de saldo
                    $customer->balanceMovements()->create([
                        'transaction_id' => $transaction->id,
                        'type' => CustomerBalanceMovementType::CREDIT_USAGE,
                        'amount' => -$balanceToUse, // Negativo porque es un "uso" de su saldo
                        'balance_after' => $customer->balance,
                        'notes' => "Uso de saldo a favor en O.S. #{$transaction->folio}",
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                // 7. PROCESAR PAGOS DIRECTOS (SI APLICA)
                if ($totalFromPayments > 0) {
                    $paymentsFromRequest = $validated['payments'];
                    
                    // Registramos los pagos (efectivo, tarjeta, etc.)
                    $paymentService->processPayments($transaction, $paymentsFromRequest, $sessionId);

                    // Si la transacción tiene un cliente, actualizamos su balance (deuda).
                    if ($customer) {
                        // INCREMENTAMOS el balance (reducimos su deuda) con el monto del abono.
                        // Ej: Si debía -500 y abona 200, su nuevo balance es -300.
                        $customer->increment('balance', $totalFromPayments);

                        // Creamos el registro del movimiento de saldo
                        $customer->balanceMovements()->create([
                            'transaction_id' => $transaction->id,
                            'type' => CustomerBalanceMovementType::PAYMENT,
                            'amount' => $totalFromPayments, // Positivo porque es un "abono"
                            'balance_after' => $customer->balance,
                            'notes' => "Abono a O.S. #{$transaction->folio}",
                            // Usamos addSecond() para asegurar un orden cronológico si también usó saldo
                            'created_at' => $now->copy()->addSecond(),
                            'updated_at' => $now->copy()->addSecond(),
                        ]);
                    }
                }
            }); // Fin de DB::transaction

        } catch (Exception $e) {
            // Si algo falla, la transacción se revierte y mostramos el error.
            return redirect()->back()->with('error', 'Error al procesar el pago: ' . $e->getMessage());
        }

        // 9. ÉXITO
        return redirect()->back()->with('success', 'Pago registrado correctamente.');
    }
}