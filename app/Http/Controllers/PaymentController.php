<?php

namespace App\Http\Controllers;

use App\Enums\CustomerBalanceMovementType;
use App\Enums\TransactionStatus;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    /**
     * Almacena un nuevo pago (abono) para una transacción.
     */
    public function store(StorePaymentRequest $request, Transaction $transaction)
    {
        DB::transaction(function () use ($request, $transaction) {
            $validated = $request->validated();

            // Corregir el desfase de zona horaria.
            $validated['payment_date'] = Carbon::parse($validated['payment_date'])->setTimezone('America/Mexico_City');

            // 1. Crear el registro del pago
            $payment = $transaction->payments()->create($validated);

            // 2. Si la transacción tiene un cliente, afectar su balance general
            if ($customer = $transaction->customer) {
                $newBalance = $customer->balance + $payment->amount;
                $customer->update(['balance' => $newBalance]);

                // 3. Registrar el movimiento de saldo para auditoría
                $customer->balanceMovements()->create([
                    'transaction_id' => $transaction->id,
                    'type' => CustomerBalanceMovementType::PAYMENT,
                    'amount' => $payment->amount,
                    'balance_after' => $newBalance,
                    'notes' => "Abono a la transacción #{$transaction->folio}",
                ]);
            }

            // 4. Verificar si la transacción ya fue saldada y actualizar su estado
            $totalAmountDue = $transaction->subtotal - $transaction->total_discount;
            $totalPaid = $transaction->payments()->sum('amount');

            if ($totalPaid >= $totalAmountDue) {
                if ($transaction->status !== TransactionStatus::COMPLETED) {
                    $transaction->update(['status' => TransactionStatus::COMPLETED]);
                }

                // 5. Si hubo un pago en exceso (excedente), registrarlo como nota en el balance.
                // El saldo del cliente ya es correcto, esto es solo para mayor claridad en el historial.
                $surplus = $totalPaid - $totalAmountDue;
                if ($surplus > 0.01 && $customer) {
                    $customer->balanceMovements()->create([
                        'transaction_id' => $transaction->id,
                        'type' => CustomerBalanceMovementType::MANUAL_ADJUSTMENT,
                        'amount' => 0, // El monto ya fue aplicado, esto es solo una anotación.
                        'balance_after' => $customer->balance,
                        'notes' => "Excedente de pago de " . number_format($surplus, 2) . " en transacción #{$transaction->folio} aplicado a saldo a favor.",
                    ]);
                }
            }
        });

        return redirect()->back()->with('success', 'Abono registrado con éxito.');
    }
}