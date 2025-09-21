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
        ];
    }

    public function messages(): array
    {
        return [
            'opening_cash_balance.required' => 'El fondo de caja inicial es obligatorio.',
            'opening_cash_balance.numeric' => 'El fondo de caja debe ser un número.',
            'user_id.required' => 'Debes seleccionar un usuario para la sesión.',
        ];
    }
}