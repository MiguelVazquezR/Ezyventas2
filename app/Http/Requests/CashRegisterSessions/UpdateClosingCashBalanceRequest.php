<?php

namespace App\Http\Requests\CashRegisterSessions;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class UpdateClosingCashBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cash_registers.sessions.access');
    }

    public function rules(): array
    {
        return [
            'closing_cash_balance' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'closing_cash_balance.required' => 'El monto de contado físico es obligatorio.',
            'closing_cash_balance.numeric' => 'El monto de contado físico debe ser un número.',
            'closing_cash_balance.min' => 'El monto de contado físico no puede ser negativo.',
        ];
    }
}
