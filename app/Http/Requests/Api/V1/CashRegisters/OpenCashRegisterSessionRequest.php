<?php

namespace App\Http\Requests\Api\V1\CashRegisters;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Opening a cash register session (shift) from the phone.
 *
 * Mirrors the web request: the cashier declares the cash fund and may correct
 * the balance of the bank accounts they manage.
 */
class OpenCashRegisterSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pos.access') ?? false;
    }

    public function rules(): array
    {
        return [
            'cash_register_id' => ['required', 'integer', 'exists:cash_registers,id'],
            'opening_cash_balance' => ['required', 'numeric', 'min:0'],
            'bank_accounts' => ['nullable', 'array'],
            'bank_accounts.*.id' => ['required', 'integer', 'exists:bank_accounts,id'],
            'bank_accounts.*.balance' => ['required', 'numeric', 'min:0'],
            // Idempotency key of the offline queue (used from phase 5 on).
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'cash_register_id.required' => 'Selecciona la caja que vas a abrir.',
            'cash_register_id.exists' => 'La caja seleccionada no existe.',
            'opening_cash_balance.required' => 'Indica el fondo de efectivo con el que abres caja.',
            'opening_cash_balance.min' => 'El fondo de efectivo no puede ser negativo.',
            'bank_accounts.*.balance.min' => 'El saldo de la cuenta no puede ser negativo.',
        ];
    }

    public function attributes(): array
    {
        return [
            'cash_register_id' => 'caja',
            'opening_cash_balance' => 'fondo de efectivo',
            'bank_accounts' => 'cuentas bancarias',
        ];
    }

    /**
     * @return array<int, array{id?: int|string, balance?: int|float|string}>
     */
    public function declaredBankAccounts(): array
    {
        return $this->validated('bank_accounts') ?? [];
    }

    public function openingCashBalance(): float
    {
        return (float) $this->validated('opening_cash_balance');
    }
}
