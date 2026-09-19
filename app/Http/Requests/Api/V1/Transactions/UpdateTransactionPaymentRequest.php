<?php

namespace App\Http\Requests\Api\V1\Transactions;

use App\Enums\PaymentMethod;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a payment of a sale (amount, method, account or notes).
 *
 * The server reconciles the bank account: it reverses the previous effect and
 * applies the new one.
 */
class UpdateTransactionPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('transactions.edit_payment') ?? false;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => [
                'required',
                Rule::in(array_map(fn (PaymentMethod $method) => $method->value, PaymentMethod::cases())),
            ],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'notes' => ['nullable', 'string', 'max:255'],
            // Idempotency key of the offline queue (used from phase 5 on).
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'El monto del pago es obligatorio.',
            'amount.min' => 'El monto del pago debe ser mayor que cero.',
            'payment_method.required' => 'Selecciona el método de pago.',
            'payment_method.in' => 'El método de pago seleccionado no es válido.',
            'bank_account_id.exists' => 'La cuenta bancaria seleccionada no existe.',
            'client_uuid.uuid' => 'El identificador de la operación no es válido.',
        ];
    }

    public function attributes(): array
    {
        return [
            'amount' => 'monto',
            'payment_method' => 'método de pago',
            'bank_account_id' => 'cuenta bancaria',
            'notes' => 'notas',
        ];
    }

    /**
     * Card and transfer payments must say where the money lands.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $method = $this->input('payment_method');

                if (
                    in_array($method, [PaymentMethod::CARD->value, PaymentMethod::TRANSFER->value], true)
                    && !$this->input('bank_account_id')
                ) {
                    $validator->errors()->add(
                        'bank_account_id',
                        'Selecciona la cuenta destino para los pagos con tarjeta o transferencia.'
                    );
                }
            },
        ];
    }
}
