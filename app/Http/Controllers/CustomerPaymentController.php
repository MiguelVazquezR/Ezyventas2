<?php

namespace App\Http\Controllers;

use App\Enums\CustomerBalanceMovementType;
use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Exception;

class CustomerPaymentController extends Controller
{
    /**
     * Almacena uno o más pagos (abonos) de un cliente y los aplica a sus deudas pendientes.
     */
    public function store(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'payments' => 'required|array|min:1',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.method' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'notes' => 'nullable|string|max:255',
        ]);

        try {
            DB::transaction(function () use ($customer, $validated) {
                
                // 1. Obtener todas las transacciones pendientes del cliente, de la más antigua a la más nueva.
                $pendingTransactions = $customer->transactions()
                    ->where('status', TransactionStatus::PENDING)
                    ->orderBy('created_at', 'asc')
                    ->get();

                // Si no hay deudas, todo el pago se va a saldo a favor.
                if ($pendingTransactions->isEmpty()) {
                    foreach ($validated['payments'] as $paymentData) {
                        $amountToAdd = (float) $paymentData['amount'];
                        $customer->increment('balance', $amountToAdd);

                        $customer->balanceMovements()->create([
                            'type' => CustomerBalanceMovementType::PAYMENT,
                            'amount' => $amountToAdd,
                            'balance_after' => $customer->balance,
                            'notes' => 'Abono a saldo a favor. ' . ($validated['notes'] ?? ''),
                        ]);
                    }
                    return; // Termina la transacción de la base de datos.
                }

                // 2. Iterar sobre cada pago recibido (efectivo, tarjeta, etc.).
                foreach ($validated['payments'] as $paymentData) {
                    $amountToApply = (float) $paymentData['amount'];
                    $paymentMethod = $paymentData['method'];

                    // 3. Aplicar cada pago a las transacciones pendientes.
                    foreach ($pendingTransactions as $transaction) {
                        if ($amountToApply <= 0.001) break; // Si este pago ya se aplicó por completo, pasar al siguiente.

                        $totalPaidOnTransaction = $transaction->payments()->sum('amount');
                        $pendingAmountOnTransaction = $transaction->total - $totalPaidOnTransaction;

                        if ($pendingAmountOnTransaction <= 0.001) continue; // Si esta deuda ya está pagada, pasar a la siguiente.

                        $amountForThisTransaction = min($amountToApply, $pendingAmountOnTransaction);

                        // Registrar el pago específico con su método correcto.
                        $transaction->payments()->create([
                            'amount' => $amountForThisTransaction,
                            'payment_method' => $paymentMethod,
                            'payment_date' => now(),
                            'status' => 'completado',
                        ]);
                        
                        // Actualizar el balance del cliente (pagar deuda lo hace menos negativo o más positivo).
                        $customer->increment('balance', $amountForThisTransaction);

                        $customer->balanceMovements()->create([
                            'transaction_id' => $transaction->id,
                            'type' => CustomerBalanceMovementType::PAYMENT,
                            'amount' => $amountForThisTransaction,
                            'balance_after' => $customer->balance,
                            'notes' => "Abono a la venta #{$transaction->folio} ({$paymentMethod}). " . ($validated['notes'] ?? ''),
                        ]);

                        $amountToApply -= $amountForThisTransaction;
                        
                        // Verificar si la transacción se liquidó por completo.
                        $newTotalPaid = $totalPaidOnTransaction + $amountForThisTransaction;
                        if ($newTotalPaid >= $transaction->total - 0.001) {
                            $transaction->update(['status' => TransactionStatus::COMPLETED]);
                        }
                    }

                    // 4. Si después de pagar deudas, aún queda remanente de ESTE pago, se va a saldo a favor.
                    if ($amountToApply > 0.001) {
                        $customer->increment('balance', $amountToApply);
                        $customer->balanceMovements()->create([
                            'type' => CustomerBalanceMovementType::PAYMENT,
                            'amount' => $amountToApply,
                            'balance_after' => $customer->balance,
                            'notes' => 'Abono a saldo a favor. ' . ($validated['notes'] ?? ''),
                        ]);
                    }
                }
            });
        } catch (Exception $e) {
            return redirect()->back()->with('error', 'Error al procesar el abono: ' . $e->getMessage());
        }

        return redirect()->back()->with('success', 'Abono registrado correctamente.');
    }
}