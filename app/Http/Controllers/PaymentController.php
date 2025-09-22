<?php

namespace App\Http\Controllers;

use App\Enums\TransactionStatus;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PaymentController extends Controller
{
    /**
     * Almacena un nuevo pago (abono) para una transacción.
     */
    public function store(StorePaymentRequest $request, Transaction $transaction)
    {
        DB::transaction(function () use ($request, $transaction) {
            $validated = $request->validated();

            // SOLUCIÓN 3: Corregir el desfase de zona horaria.
            // Se asume que la zona horaria del usuario es la central de México.
            // En una app más compleja, esto vendría de la configuración del usuario o sucursal.
            $validated['payment_date'] = Carbon::parse($validated['payment_date'], 'America/Mexico_City')->setTimezone('UTC');

            $payment = $transaction->payments()->create($validated);

            // SOLUCIÓN 2: Lógica de pago completo y excedente.
            $totalAmount = $transaction->subtotal - $transaction->total_discount;
            $totalPaid = $transaction->payments()->where('status', 'completado')->sum('amount');
            
            if ($totalPaid >= $totalAmount) {
                // Marcar la transacción como completada si no lo estaba
                if ($transaction->status !== TransactionStatus::COMPLETED) {
                    $transaction->update(['status' => TransactionStatus::COMPLETED]);
                }

                // Calcular el excedente y añadirlo al saldo a favor del cliente
                $surplus = $totalPaid - $totalAmount;
                if ($surplus > 0.01 && $transaction->customer) {
                    $transaction->customer->increment('balance', $surplus);

                    // Opcional: Registrar el movimiento de saldo para auditoría
                    $transaction->customer->balanceMovements()->create([
                        'type' => 'ajuste_manual', // Podrías crear un Enum 'excedente_pago'
                        'amount' => $surplus,
                        'balance_after' => $transaction->customer->fresh()->balance,
                        'notes' => "Excedente del pago para la venta #{$transaction->folio}",
                    ]);
                }
            }
        });

        // Inertia recargará la página Show.vue con los datos actualizados.
        return redirect()->back()->with('success', 'Abono registrado con éxito.');
    }
}