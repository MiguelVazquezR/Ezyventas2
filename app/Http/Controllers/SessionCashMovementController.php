<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSessionCashMovementRequest;
use App\Models\CashRegisterSession;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth; // <-- Importante: Añadir Auth

class SessionCashMovementController extends Controller //implements HasMiddleware
{
    // public static function middleware(): array
    // {
    //     return [
    //         new Middleware('can:cash_registers.sessions.create_movements', only: ['store']),
    //     ];
    // }
    /**
     * Almacena un nuevo movimiento de efectivo (ingreso/egreso) para una sesión de caja.
     */
    public function store(StoreSessionCashMovementRequest $request, CashRegisterSession $session)
    {
        // Autorización para asegurar que la sesión esté abierta
        if ($session->status !== \App\Enums\CashRegisterSessionStatus::OPEN) {
            return back()->with(['error' => 'No se pueden agregar movimientos a una sesión cerrada.']);
        }

        // CORRECCIÓN: Fusionamos los datos validados con el ID del usuario actual.
        $data = array_merge($request->validated(), [
            'user_id' => Auth::id() 
        ]);

        $session->cashMovements()->create($data);

        return redirect()->back()->with('success', 'Movimiento de efectivo registrado con éxito.');
    }
}