<?php

namespace App\Http\Requests\Api\V1\Transactions;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cancellation of a sale: refund the money or cancel keeping it (penalty).
 */
class CancelTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('transactions.cancel') ?? false;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['refund', 'penalty'])],
            'refund_method' => ['required_if:action,refund', Rule::in(['cash', 'balance', 'transfer'])],
            'bank_account_id' => ['required_if:refund_method,transfer', 'integer', 'exists:bank_accounts,id'],
            // Idempotency key of the offline queue (used from phase 5 on).
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'action.required' => 'Indica si la venta se reembolsa o se cancela con penalización.',
            'action.in' => 'La acción seleccionada no es válida.',
            'refund_method.required_if' => 'Indica cómo se devolverá el dinero al cliente.',
            'refund_method.in' => 'El método de reembolso no es válido.',
            'bank_account_id.required_if' => 'Selecciona la cuenta bancaria para el reembolso por transferencia.',
            'client_uuid.uuid' => 'El identificador de la operación no es válido.',
        ];
    }

    public function attributes(): array
    {
        return [
            'action' => 'acción',
            'refund_method' => 'método de reembolso',
            'bank_account_id' => 'cuenta bancaria',
        ];
    }
}
