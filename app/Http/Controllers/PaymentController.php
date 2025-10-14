<?php

namespace App\Http\Controllers;

use App\Enums\CustomerBalanceMovementType;
use App\Models\Transaction;
use App\Services\PaymentService; // Se importa el servicio
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Exception;

class PaymentController extends Controller
{
    /**
     * Almacena nuevos pagos para una transacción existente (abonos).
     */
    public function store(Request $request, Transaction $transaction, PaymentService $paymentService)
    {
        $validated = $request->validate([
            'payments' => 'required|array|min:1',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.method' => ['required', Rule::in(['efectivo', 'tarjeta', 'transferencia'])],
            'payments.*.notes' => 'nullable|string',
            // <-- INICIA VALIDACIÓN
            'payments.*.bank_account_id' => [
                'nullable',
                'integer',
                'exists:bank_accounts,id',
                Rule::requiredIf(function () use ($request) {
                    // Requerido si algún pago es por tarjeta o transferencia
                    foreach ($request->input('payments', []) as $payment) {
                        if (in_array($payment['method'], ['tarjeta', 'transferencia'])) {
                            return true;
                        }
                    }
                    return false;
                }),
            ],
            // <-- TERMINA VALIDACIÓN
            'cash_register_session_id' => 'required|exists:cash_register_sessions,id,status,abierta',
        ]);

        try {
            DB::transaction(function () use ($validated, $transaction, $paymentService) {
                $customer = $transaction->customer;
                $payments = $validated['payments'];
                $sessionId = $validated['cash_register_session_id'];

                $remainingDue = $transaction->total - $transaction->payments()->sum('amount');
                $totalAmountToPayInRequest = array_sum(array_column($payments, 'amount'));

                // Se asegura que el monto del abono no sea mayor al saldo pendiente.
                if ($totalAmountToPayInRequest > $remainingDue + 0.01) {
                    throw new Exception('El monto del pago excede el saldo pendiente de la transacción.');
                }
                
                // Se delega la creación del registro de pago al servicio.
                $paymentService->processPayments($transaction, $payments, $sessionId);

                // La lógica de negocio, como actualizar el balance del cliente, permanece aquí.
                if ($customer) {
                    // El balance del cliente se actualiza para reflejar que su deuda ha disminuido.
                    $customer->increment('balance', $totalAmountToPayInRequest);
                    
                    $customer->balanceMovements()->create([
                        'transaction_id' => $transaction->id,
                        'type' => CustomerBalanceMovementType::PAYMENT,
                        'amount' => $totalAmountToPayInRequest,
                        'balance_after' => $customer->balance,
                        'notes' => "Abono a transacción {$transaction->folio}",
                    ]);
                }
            });
        } catch (Exception $e) {
            return redirect()->back()->with('error', 'Error al procesar el pago: ' . $e->getMessage());
        }

        return redirect()->back()->with('success', 'Pago registrado correctamente.');
    }
}