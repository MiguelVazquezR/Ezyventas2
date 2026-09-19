<?php

namespace App\Http\Requests\Api\V1\Pos;

use App\Enums\PaymentMethod;
use App\Models\Customer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sale registered from the phone (checkout of the POS cart).
 *
 * Mirrors the web POS validation. The amounts are recalculated by the server
 * through the same service the web uses, so both clients book the same sale.
 */
class RegisterSaleRequest extends FormRequest
{
    /**
     * Payment methods accepted on a sale. Balance is sent as `use_balance`.
     */
    protected const SALE_PAYMENT_METHODS = [
        PaymentMethod::CASH,
        PaymentMethod::CARD,
        PaymentMethod::TRANSFER,
        PaymentMethod::BALANCE,
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('pos.create_sale') ?? false;
    }

    public function rules(): array
    {
        return [
            'cash_register_session_id' => ['required', 'integer', 'exists:cash_register_sessions,id'],
            // Customers of the branch only: the web POS offers exactly these.
            'customerId' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where('branch_id', $this->user()?->branch_id),
            ],
            'guest_name' => ['nullable', 'string', 'max:255'],
            'cartItems' => ['required', 'array', 'min:1'],
            'cartItems.*.id' => ['required', 'integer', 'exists:products,id'],
            'cartItems.*.product_attribute_id' => ['nullable', 'integer', 'exists:product_attributes,id'],
            'cartItems.*.quantity' => ['required', 'numeric', 'min:1'],
            'cartItems.*.unit_price' => ['required', 'numeric', 'min:0'],
            'cartItems.*.description' => ['required', 'string', 'max:255'],
            'cartItems.*.discount' => ['required', 'numeric'],
            'cartItems.*.discount_reason' => ['nullable', 'string', 'max:255'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'total_discount' => ['nullable', 'numeric', 'min:0'],
            'total' => ['required', 'numeric', 'min:0'],
            'payments' => ['sometimes', 'array'],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01'],
            'payments.*.method' => [
                'required',
                Rule::in(array_map(fn (PaymentMethod $method) => $method->value, self::SALE_PAYMENT_METHODS)),
            ],
            'payments.*.bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'payments.*.notes' => ['nullable', 'string', 'max:255'],
            'use_balance' => ['required', 'boolean'],
            'layaway_expiration_date' => ['nullable', 'date'],
            // Idempotency key of the offline queue (used from phase 5 on).
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'cartItems.required' => 'Agrega al menos un producto a la venta.',
            'cartItems.*.quantity.min' => 'La cantidad debe ser mayor que cero.',
            'customerId.exists' => 'El cliente seleccionado no pertenece a tu sucursal.',
            'payments.*.amount.min' => 'El monto de cada pago debe ser mayor que cero.',
            'payments.*.method.in' => 'El método de pago seleccionado no es válido.',
            'total.required' => 'El total de la venta es obligatorio.',
            'use_balance.required' => 'Indica si el cliente usará su saldo a favor.',
            'client_uuid.uuid' => 'El identificador de la operación no es válido.',
        ];
    }

    public function attributes(): array
    {
        return [
            'cartItems' => 'productos',
            'payments' => 'pagos',
            'total' => 'total',
        ];
    }

    /**
     * Customer of the sale, scoped to the branch of the user.
     */
    public function customer(): ?Customer
    {
        $customerId = $this->validated('customerId');

        if (!$customerId) {
            return null;
        }

        return Customer::where('branch_id', $this->user()->branch_id)->find($customerId);
    }

    /**
     * Amount that would be left as debt (0 when the sale is fully covered).
     *
     * Mirrors the service: it uses the customer balance first and then the
     * direct payments.
     */
    public function pendingCreditAmount(?Customer $customer): float
    {
        $total = (float) $this->validated('total');
        $paid = (float) collect($this->validated('payments') ?? [])->sum('amount');
        $balanceToUse = ($this->validated('use_balance') && $customer)
            ? min((float) $customer->balance, $total)
            : 0.0;

        return max(0, round($total - $paid - $balanceToUse, 2));
    }

    /**
     * Data the payment service expects (same keys as the web request).
     *
     * @return array<string, mixed>
     */
    public function saleData(): array
    {
        return [
            'cash_register_session_id' => (int) $this->validated('cash_register_session_id'),
            'guest_name' => $this->validated('guest_name'),
            'cartItems' => $this->validated('cartItems'),
            'subtotal' => $this->validated('subtotal'),
            'total_discount' => $this->validated('total_discount') ?? 0,
            'total' => $this->validated('total'),
            'payments' => $this->validated('payments') ?? [],
            'use_balance' => (bool) $this->validated('use_balance'),
            'layaway_expiration_date' => $this->validated('layaway_expiration_date'),
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
                foreach ($this->input('payments', []) as $index => $payment) {
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
