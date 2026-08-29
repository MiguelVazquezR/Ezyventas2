<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCashRegisterSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cash_register_id' => 'required|exists:cash_registers,id',
            'opening_cash_balance' => 'required|numeric|min:0',
            'user_id' => 'required|exists:users,id',
            'bank_accounts' => 'nullable|array',
            'bank_accounts.*.id' => 'required|integer|exists:bank_accounts,id',
            'bank_accounts.*.balance' => 'required|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'opening_cash_balance.required' => 'El fondo de caja inicial es obligatorio.',
            'opening_cash_balance.numeric' => 'El fondo de caja debe ser un número.',
            'user_id.required' => 'Debes seleccionar un usuario para la sesión.',
            'bank_accounts.*.id.required' => 'Cada cuenta bancaria debe incluir su identificador.',
            'bank_accounts.*.id.exists' => 'Una de las cuentas bancarias seleccionadas ya no existe.',
            'bank_accounts.*.balance.required' => 'Cada cuenta bancaria debe declarar un saldo.',
            'bank_accounts.*.balance.numeric' => 'El saldo bancario declarado debe ser un número.',
        ];
    }
}