<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\TransactionPaymentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Exception;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    // Inyectar el nuevo servicio
    public function __construct(protected TransactionPaymentService $transactionPaymentService)
    {
    }

    /**
     * Almacena nuevos pagos (abonos) para una transacción existente.
     */
    public function store(Request $request, Transaction $transaction)
    {
        // 1. VALIDACIÓN (Sin cambios)
         $rules = [
            'cash_register_session_id' => 'required|exists:cash_register_sessions,id,status,abierta',
            'use_balance' => 'required|boolean',
            'payments' => 'nullable|array',
            'payments.*.amount' => 'required_with:payments|numeric|min:0.01',
            'payments.*.method' => ['required_with:payments', Rule::in(['efectivo', 'tarjeta', 'transferencia'])],
            'payments.*.notes' => 'nullable|string',
        ];

        $messages = [
            'cash_register_session_id.required' => 'No se ha proporcionado una sesión de caja activa.',
            'payments.*.bank_account_id.required' => 'Se requiere una cuenta destino para pagos con tarjeta o transferencia.',
            'payments.*.bank_account_id.exists' => 'La cuenta bancaria seleccionada no es válida.',
        ];

        $validated = $request->validate($rules, $messages);
        
        if (empty($validated['payments']) && !$validated['use_balance']) {
            throw ValidationException::withMessages([
                'payments' => 'Debe proporcionar al menos un método de pago o usar el saldo a favor.',
            ]);
        }
        
        $sessionId = (int) $validated['cash_register_session_id'];

        try {
            // 2. DELEGAR LÓGICA AL SERVICIO
            $this->transactionPaymentService->applyPaymentToTransaction(
                $transaction,
                $validated,
                $sessionId
            );

        } catch (Exception $e) {
            return redirect()->back()->with('error', 'Error al procesar el pago: ' . $e->getMessage());
        }

        // 3. ÉXITO
        return redirect()->back()->with('success', 'Pago registrado correctamente.');
    }
}