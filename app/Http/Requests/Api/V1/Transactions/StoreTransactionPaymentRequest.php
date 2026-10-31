<?php

namespace App\Http\Requests\Api\V1\Transactions;

use App\Enums\PaymentMethod;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payment (abono) registered against an existing sale.
 */
class StoreTransactionPaymentRequest extends FormRequest
{
    /**
     * Methods accepted on a payment: balance goes through `use_balance`.
     *
     * @var array<int, PaymentMethod>
     */
    protected const PAYMENT_METHODS = [
        PaymentMethod::CASH,
        PaymentMethod::CARD,
        PaymentMethod::TRANSFER,
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('transactions.add_payment') ?? false;
    }

    public function rules(): array
    {
        return [
            'cash_register_session_id' => ['required', 'integer', 'exists:cash_register_sessions,id'],
            'use_balance' => ['sometimes', 'boolean'],
            // Empty is accepted when the customer balance covers the sale.
            'payments' => ['nullable', 'array'],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'min:0.01'],
            'payments.*.method' => [
                'required_with:payments',
                Rule::in(array_map(fn (PaymentMethod $method) => $method->value, self::PAYMENT_METHODS)),
            ],
            'payments.*.bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'payments.*.notes' => ['nullable', 'string', 'max:255'],
            // Idempotency key of the offline queue (used from phase 5 on).
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'payments.*.amount.min' => 'El monto de cada abono debe ser mayor que cero.',
            'payments.*.method.in' => 'El método de pago seleccionado no es válido.',
            'payments.*.bank_account_id.exists' => 'La cuenta bancaria seleccionada no existe.',
            'client_uuid.uuid' => 'El identificador de la operación no es válido.',
        ];
    }

    public function attributes(): array
    {
        return [
            'payments' => 'abonos',
            'cash_register_session_id' => 'sesión de caja',
            'use_balance' => 'uso de saldo',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function paymentData(): array
    {
        return [
            'cash_register_session_id' => (int) $this->validated('cash_register_session_id'),
            'use_balance' => (bool) $this->validated('use_balance', false),
            'payments' => $this->validated('payments') ?? [],
        ];
    }

    /**
     * A payment must bring money or use the customer balance, and card or
     * transfer payments must say where the money lands.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $payments = $this->input('payments', []);

                if (empty($payments) && !$this->boolean('use_balance')) {
                    $validator->errors()->add(
                        'payments',
                        'Debe proporcionar al menos un método de pago o usar el saldo a favor.'
                    );

                    return;
                }

                foreach ($payments as $index => $payment) {
                    $method = $payment['method'] ?? null;

                    if (
                        in_array($method, [PaymentMethod::CARD->value, PaymentMethod::TRANSFER->value], true)
                        && empty($payment['bank_account_id'])
                    ) {
                        $validator->errors()->add(
                            "payments.{$index}.bank_account_id",
                            'Selecciona la cuenta destino para los pagos con tarjeta o transferencia.'
                        );
                    }
                }
            },
        ];
    }
}
