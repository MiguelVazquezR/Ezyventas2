<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCashRegisterSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'closing_cash_balance' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ];
    }

    public function messages()
    {
        return [
            'closing_cash_balance.required' => 'El monto de cierre es obligatorio.',
            'closing_cash_balance.numeric' => 'El monto de cierre debe ser un número.',
        ];
    }
}