<?php

namespace App\Http\Controllers;

use App\Enums\CashRegisterSessionStatus;
use App\Http\Requests\StoreCashRegisterSessionRequest;
use App\Http\Requests\UpdateCashRegisterSessionRequest; // <-- Añadir
use App\Models\CashRegister;
use App\Models\CashRegisterSession; // <-- Añadir
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class CashRegisterSessionController extends Controller
{
    /**
     * Inicia una nueva sesión de caja (Abre la caja).
     */
    public function store(StoreCashRegisterSessionRequest $request)
    {
        $cashRegister = CashRegister::findOrFail($request->input('cash_register_id'));
        $user = User::findOrFail($request->input('user_id'));

        if ($cashRegister->in_use) {
            return redirect()->back()->withErrors(['error' => 'Esta caja ya está en uso.']);
        }

        if ($user->cashRegisterSessions()->where('status', 'abierta')->exists()) {
            return redirect()->back()->withErrors(['error' => 'Este usuario ya tiene una sesión activa.']);
        }

        DB::transaction(function () use ($request, $cashRegister, $user) {
            $cashRegister->sessions()->create([
                'user_id' => $user->id,
                'opening_cash_balance' => $request->input('opening_cash_balance'),
                'status' => CashRegisterSessionStatus::OPEN,
                'opened_at' => now(),
            ]);
            $cashRegister->update(['in_use' => true]);
        });

        return redirect()->back()->with('success', 'La caja ha sido abierta con éxito.');
    }

    /**
     * Cierra una sesión de caja existente (Realiza el corte).
     */
    public function update(UpdateCashRegisterSessionRequest $request, CashRegisterSession $cashRegisterSession)
    {
        DB::transaction(function () use ($request, $cashRegisterSession) {
            $validated = $request->validated();

            // Calcular el total de efectivo esperado
            $cashSales = $cashRegisterSession->transactions()
                ->where('status', 'completado')
                ->whereHas('payments', fn($q) => $q->where('payment_method', 'efectivo'))
                ->withSum('payments', 'amount')
                ->get()
                ->sum('payments_sum_amount');

            $inflows = $cashRegisterSession->cashMovements()->where('type', 'ingreso')->sum('amount');
            $outflows = $cashRegisterSession->cashMovements()->where('type', 'egreso')->sum('amount');

            $calculatedTotal = $cashRegisterSession->opening_cash_balance + $cashSales + $inflows - $outflows;
            $difference = $validated['closing_cash_balance'] - $calculatedTotal;

            // Actualizar la sesión
            $cashRegisterSession->update([
                'closing_cash_balance' => $validated['closing_cash_balance'],
                'calculated_cash_total' => $calculatedTotal,
                'cash_difference' => $difference,
                'notes' => $validated['notes'],
                'status' => CashRegisterSessionStatus::CLOSED,
                'closed_at' => now(),
            ]);

            // Marcar la caja como libre
            $cashRegisterSession->cashRegister->update(['in_use' => false]);
        });

        return redirect()->route('cash-registers.show', $cashRegisterSession->cash_register_id)
            ->with('success', 'Corte de caja realizado con éxito.');
    }
}
