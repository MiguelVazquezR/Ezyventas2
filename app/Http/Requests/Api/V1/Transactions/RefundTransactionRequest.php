<?php

namespace App\Http\Requests\Api\V1\Transactions;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Refund of a sale: equivalent to cancelling with `action = refund`.
 */
class RefundTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('transactions.refund') ?? false;
    }

    public function rules(): array
    {
        return [
            'refund_method' => ['required', Rule::in(['cash', 'balance', 'transfer'])],
            'bank_account_id' => ['required_if:refund_method,transfer', 'integer', 'exists:bank_accounts,id'],
            // Idempotency key of the offline queue (used from phase 5 on).
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'refund_method.required' => 'Indica cómo se devolverá el dinero al cliente.',
            'refund_method.in' => 'El método de reembolso no es válido.',
            'bank_account_id.required_if' => 'Selecciona la cuenta bancaria para el reembolso por transferencia.',
            'client_uuid.uuid' => 'El identificador de la operación no es válido.',
        ];
    }

    public function attributes(): array
    {
        return [
            'refund_method' => 'método de reembolso',
            'bank_account_id' => 'cuenta bancaria',
        ];
    }
}
