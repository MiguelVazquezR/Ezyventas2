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

                // Gracias al accesor en el modelo Transaction, ahora `$transaction->total` funciona.
                $remainingDue = $transaction->total - $transaction->payments()->sum('amount');
                
                if ($remainingDue <= 0) {
                    return;
                }

                $amountPaidInThisRequest = 0;

                foreach ($payments as $paymentData) {
                    if ($amountPaidInThisRequest >= $remainingDue) {
                        break;
                    }

                    $amountOffered = (float) $paymentData['amount'];
                    $amountToRecord = min($amountOffered, $remainingDue - $amountPaidInThisRequest);

                    if ($amountToRecord <= 0) {
                        continue;
                    }

                    $transaction->payments()->create([
                        'amount' => $amountToRecord,
                        'payment_method' => $paymentData['method'],
                        'payment_date' => now(),
                        'status' => 'completado',
                    ]);
                    
                    $amountPaidInThisRequest += $amountToRecord;

                    if ($customer) {
                        $customer->increment('balance', $amountToRecord);
                        
                        $customer->balanceMovements()->create([
                            'transaction_id' => $transaction->id,
                            'type' => CustomerBalanceMovementType::PAYMENT,
                            'amount' => $amountToRecord,
                            'balance_after' => $customer->balance,
                            'notes' => "Abono a transacción {$transaction->folio}",
                        ]);
                    }
                }
                
                // Si se usó crédito, se registra el cargo al balance
                $totalPaid = $transaction->fresh()->payments()->sum('amount');
                $finalAmountDue = $transaction->total - $totalPaid;
                
                if($customer && $finalAmountDue > 0.01 && $finalAmountDue <= $customer->available_credit) {
                     $customer->decrement('balance', $finalAmountDue);
                     $customer->balanceMovements()->create([
                        'transaction_id' => $transaction->id,
                        'type' => CustomerBalanceMovementType::CREDIT_SALE,
                        'amount' => -$finalAmountDue,
                        'balance_after' => $customer->balance,
                        'notes' => "Cargo a crédito para transacción {$transaction->folio}",
                    ]);
                    // Se considera pagada si el resto se fue a crédito
                    $finalAmountDue = 0;
                }

                // Se actualiza el estado final de la transacción.
                $newStatus = ($finalAmountDue <= 0.01) ? TransactionStatus::COMPLETED : TransactionStatus::PENDING;
                $transaction->update(['status' => $newStatus]);
            });
        } catch (Exception $e) {
            return redirect()->back()->with('error', 'Error al procesar el pago: ' . $e->getMessage());
        }

        return redirect()->back()->with('success', 'Pago registrado correctamente.');
    }
}