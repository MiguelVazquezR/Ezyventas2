<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSessionCashMovementRequest;
use App\Models\CashRegisterSession;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class SessionCashMovementController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:cash_registers_sessions.create_movements', only: ['store']),
        ];
    }
    /**
     * Almacena un nuevo movimiento de efectivo (ingreso/egreso) para una sesión de caja.
     */
    public function store(StoreSessionCashMovementRequest $request, CashRegisterSession $session)
    {
        // Autorización para asegurar que la sesión esté abierta
        if ($session->status !== \App\Enums\CashRegisterSessionStatus::OPEN) {
            return back()->withErrors(['error' => 'No se pueden agregar movimientos a una sesión cerrada.']);
        }

        $session->cashMovements()->create($request->validated());

        return redirect()->back()->with('success', 'Movimiento de efectivo registrado con éxito.');
    }
}