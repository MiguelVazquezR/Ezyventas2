<?php

namespace App\Http\Controllers;

use App\Enums\CustomerBalanceMovementType;
use App\Enums\TransactionStatus;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Exception;

class TransactionPaymentController extends Controller
{
    /**
     * Almacena nuevos pagos para una transacción existente.
     * Este método es el punto central para registrar pagos desde cualquier módulo.
     */
    public function store(Request $request, Transaction $transaction)
    {
        $validated = $request->validate([
            'payments' => 'sometimes|array',
            'payments.*.amount' => 'required_with:payments|numeric|min:0.01',
            'payments.*.method' => ['required_with:payments', Rule::in(['efectivo', 'tarjeta', 'transferencia'])],
        ]);

        try {
            DB::transaction(function () use ($validated, $transaction) {
                $customer = $transaction->customer;
                $payments = $validated['payments'] ?? [];

                // 1. Registrar los nuevos pagos
                foreach ($payments as $paymentData) {
                    $payment = $transaction->payments()->create([
                        'amount' => $paymentData['amount'],
                        'payment_method' => $paymentData['method'],
                        'payment_date' => now(),
                        'status' => 'completado',
                    ]);

                    // 2. Afectar el balance del cliente por cada pago (abono)
                    if ($customer) {
                        $balanceBefore = $customer->balance;
                        $customer->increment('balance', $payment->amount);
                        
                        $customer->balanceMovements()->create([
                            'transaction_id' => $transaction->id,
                            'type' => CustomerBalanceMovementType::PAYMENT,
                            'amount' => $payment->amount,
                            'balance_after' => $customer->balance,
                            'notes' => "Abono a transacción {$transaction->folio} / Orden de Servicio #{$transaction->transactionable_id}",
                        ]);
                    }
                }

                // 3. Recalcular totales y actualizar estado de la transacción
                $totalPaid = $transaction->payments()->sum('amount');
                $totalSale = $transaction->total;
                $amountDue = $totalSale - $totalPaid;

                // 4. Validar crédito si aún queda un saldo pendiente
                if ($amountDue > 0.01 && $customer && $amountDue > $customer->available_credit) {
                    throw new Exception('El crédito disponible del cliente no es suficiente para cubrir el monto restante.');
                }
                
                // 5. Actualizar el estado de la transacción
                $newStatus = ($amountDue <= 0.01) ? TransactionStatus::COMPLETED : TransactionStatus::PENDING;
                $transaction->update(['status' => $newStatus]);
            });
        } catch (Exception $e) {
            return redirect()->back()->with('error', 'Error al procesar el pago: ' . $e->getMessage());
        }

        return redirect()->back()->with('success', 'Pago registrado correctamente.');
    }
}