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

                // REFACTOR: Usamos el Accesor de nuestro modelo Transaction
                $remainingDue = $transaction->remaining_due;
                
                if ($remainingDue <= 0.01) {
                    return;
                }

                $amountPaidInThisRequest = 0;

                // 1. Procesar los pagos recibidos
                foreach ($payments as $paymentData) {
                    if ($amountPaidInThisRequest >= $remainingDue) {
                        break;
                    }

                    $amountOffered = (float) $paymentData['amount'];
                    $amountToRecord = min($amountOffered, $remainingDue - $amountPaidInThisRequest);

                    if ($amountToRecord <= 0.01) {
                        continue;
                    }

                    $transaction->payments()->create([
                        'amount' => $amountToRecord,
                        'payment_method' => $paymentData['method'],
                        'payment_date' => now(),
                        'status' => 'completado',
                    ]);
                    
                    $amountPaidInThisRequest += $amountToRecord;

                    // REFACTOR: Delegamos al modelo Customer el registro del abono y su bitácora
                    if ($customer) {
                        $customer->payDebt(
                            amount: $amountToRecord, 
                            transactionId: $transaction->id, 
                            notes: "Abono a transacción {$transaction->folio}"
                        );
                    }
                }
                
                // 2. Refrescar la transacción para ver cuánto falta realmente
                $transaction->refresh();
                $finalAmountDue = $transaction->remaining_due;
                
                // 3. Si aún queda deuda, pero el cliente tiene crédito disponible, se envía a su línea de crédito
                if ($customer && $finalAmountDue > 0.01 && $finalAmountDue <= $customer->available_credit) {
                     
                     // REFACTOR: Delegamos al modelo Customer el registro del cargo y su bitácora
                     $customer->addDebt(
                         amount: $finalAmountDue,
                         debtType: CustomerBalanceMovementType::CREDIT_SALE,
                         transactionId: $transaction->id,
                         notes: "Cargo a crédito para transacción {$transaction->folio}"
                     );

                    // Al usar el crédito del cliente, para fines de esta caja, la transacción queda "cubierta"
                    $finalAmountDue = 0;
                }

                // 4. Se actualiza el estado final de la transacción.
                $newStatus = ($finalAmountDue <= 0.01) ? TransactionStatus::COMPLETED : TransactionStatus::PENDING;
                $transaction->update(['status' => $newStatus]);
            });
        } catch (Exception $e) {
            return redirect()->back()->with('error', 'Error al procesar el pago: ' . $e->getMessage());
        }

        return redirect()->back()->with('success', 'Pago registrado correctamente.');
    }
}