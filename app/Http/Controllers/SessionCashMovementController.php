<?php

namespace App\Http\Controllers;

use App\Enums\CashRegisterSessionStatus;
use App\Http\Requests\StoreSessionCashMovementRequest;
use App\Models\CashRegisterSession;
use App\Models\SessionCashMovement;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;

class SessionCashMovementController extends Controller implements HasMiddleware
{
    /**
     * Define los middleware y permisos para este controlador.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:cash_registers.sessions.create_movements', only: ['store']),
            new Middleware('can:cash_registers.sessions.edit_movements', only: ['update']),
            new Middleware('can:cash_registers.sessions.delete_movements', only: ['destroy']),
        ];
    }

    /**
     * Almacena un nuevo movimiento de efectivo (ingreso/egreso) para una sesión de caja.
     */
    public function store(StoreSessionCashMovementRequest $request, CashRegisterSession $session)
    {
        // Autorización para asegurar que la sesión esté abierta
        if ($session->status !== CashRegisterSessionStatus::OPEN) {
            return back()->with(['error' => 'No se pueden agregar movimientos a una sesión cerrada.']);
        }

        $data = array_merge($request->validated(), [
            'user_id' => Auth::id() 
        ]);

        $session->cashMovements()->create($data);

        return redirect()->back()->with('success', 'Movimiento de efectivo registrado con éxito.');
    }

    /**
     * Actualiza un movimiento de efectivo existente.
     * NOTA: La variable se llama $movement para coincidir con la ruta {movement} y evitar el error 404.
     */
    public function update(Request $request, SessionCashMovement $movement)
    {
        // 1. Encontrar a qué sesión pertenece este movimiento
        $session = CashRegisterSession::findOrFail($movement->cash_register_session_id);

        // 2. Validar que la sesión siga abierta
        if ($session->status !== CashRegisterSessionStatus::OPEN) {
            return back()->with(['error' => 'No se pueden editar movimientos de una sesión que ya ha sido cerrada.']);
        }

        // 3. Validar los datos de entrada
        $validated = $request->validate([
            'type' => 'required|in:ingreso,egreso',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:255',
            'notes' => 'nullable|string|max:255',
        ]);

        // 4. Actualizar el registro
        $movement->update($validated);

        return redirect()->back()->with('success', 'Movimiento actualizado correctamente.');
    }

    /**
     * Elimina un movimiento de efectivo.
     * NOTA: La variable se llama $movement para coincidir con la ruta {movement} y evitar el error 404.
     */
    public function destroy(SessionCashMovement $movement)
    {
        // 1. Encontrar la sesión asociada
        $session = CashRegisterSession::findOrFail($movement->cash_register_session_id);

        // 2. Validar que la sesión siga abierta
        if ($session->status !== CashRegisterSessionStatus::OPEN) {
            return back()->with(['error' => 'No se pueden eliminar movimientos de una sesión que ya ha sido cerrada.']);
        }

        // 3. Eliminar el registro
        $movement->delete();

        return redirect()->back()->with('success', 'Movimiento eliminado de la sesión de caja.');
    }
}