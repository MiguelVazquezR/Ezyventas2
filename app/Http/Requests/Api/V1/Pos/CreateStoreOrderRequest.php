<?php

namespace App\Http\Requests\Api\V1\Pos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Order (pedido) created from the phone: goods delivered later, stock reserved.
 */
class CreateStoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pos.create_sale') ?? false;
    }

    public function rules(): array
    {
        return [
            'cash_register_session_id' => ['required', 'integer', 'exists:cash_register_sessions,id'],
            'customerId' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where('branch_id', $this->user()?->branch_id),
            ],
            'cartItems' => ['required', 'array', 'min:1'],
            'cartItems.*.id' => ['required', 'integer', 'exists:products,id'],
            'cartItems.*.product_attribute_id' => ['nullable', 'integer', 'exists:product_attributes,id'],
            'cartItems.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'cartItems.*.unit_price' => ['required', 'numeric', 'min:0'],
            'cartItems.*.description' => ['required', 'string', 'max:255'],
            'cartItems.*.discount' => ['nullable', 'numeric'],
            'contact_info' => ['required', 'array'],
            'contact_info.name' => ['required', 'string', 'min:2', 'max:255'],
            'contact_info.phone' => ['nullable', 'string', 'max:20'],
            'contact_info.type' => ['nullable', Rule::in(['pedido', 'comanda'])],
            'delivery_date' => ['required', 'date'],
            'shipping_address' => ['nullable', 'string', 'max:255'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'total_discount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Idempotency key of the offline queue (used from phase 5 on).
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'cartItems.required' => 'Agrega al menos un producto al pedido.',
            'contact_info.name.required' => 'Escribe el nombre de quien recibe el pedido.',
            'contact_info.name.min' => 'El nombre del contacto debe tener al menos 2 caracteres.',
            'delivery_date.required' => 'Indica la fecha de entrega del pedido.',
            'customerId.exists' => 'El cliente seleccionado no pertenece a tu sucursal.',
            'client_uuid.uuid' => 'El identificador de la operación no es válido.',
        ];
    }

    public function attributes(): array
    {
        return [
            'cartItems' => 'productos',
            'contact_info' => 'datos de contacto',
            'contact_info.name' => 'nombre del contacto',
            'delivery_date' => 'fecha de entrega',
        ];
    }

    /**
     * Data the payment service expects (same keys as the web request).
     *
     * @return array<string, mixed>
     */
    public function orderData(): array
    {
        return [
            'cash_register_session_id' => (int) $this->validated('cash_register_session_id'),
            'customer_id' => $this->validated('customerId'),
            'cartItems' => $this->validated('cartItems'),
            'contact_info' => $this->validated('contact_info'),
            'delivery_date' => $this->validated('delivery_date'),
            'shipping_address' => $this->validated('shipping_address'),
            'shipping_cost' => $this->validated('shipping_cost') ?? 0,
            'subtotal' => $this->validated('subtotal'),
            'total_discount' => $this->validated('total_discount') ?? 0,
            'notes' => $this->validated('notes'),
        ];
    }
}
