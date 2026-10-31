<?php

namespace App\Http\Requests\Api\V1\CashRegisters;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Closing the register (the cut): the cashier counts the drawer and the server
 * reconciles the cash difference and the bank balances.
 */
class CloseCashRegisterSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pos.access') ?? false;
    }

    public function rules(): array
    {
        return [
            'closing_cash_balance' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Idempotency key of the offline queue (used from phase 5 on).
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'closing_cash_balance.required' => 'El monto de cierre es obligatorio.',
            'closing_cash_balance.numeric' => 'El monto de cierre debe ser un número.',
            'closing_cash_balance.min' => 'El monto de cierre no puede ser negativo.',
            'notes.max' => 'Las notas de arqueo no pueden pasar de 1000 caracteres.',
            'client_uuid.uuid' => 'El identificador de la operación no es válido.',
        ];
    }

    public function attributes(): array
    {
        return [
            'closing_cash_balance' => 'monto de cierre',
            'notes' => 'notas de arqueo',
        ];
    }
}
