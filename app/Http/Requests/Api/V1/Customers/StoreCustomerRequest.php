<?php

namespace App\Http\Requests\Api\V1\Customers;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Quick customer creation from the mobile POS.
 */
class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('customers.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:customers,email'],
            'phone' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'array'],
            'address.street' => ['nullable', 'string', 'max:255'],
            'address.exterior_number' => ['nullable', 'string', 'max:20'],
            'address.interior_number' => ['nullable', 'string', 'max:20'],
            'address.neighborhood' => ['nullable', 'string', 'max:255'],
            'address.zip_code' => ['nullable', 'string', 'max:10'],
            'address.city' => ['nullable', 'string', 'max:255'],
            'address.state' => ['nullable', 'string', 'max:255'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            // Idempotency key of the offline queue (used from phase 5 on).
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Escribe el nombre del cliente.',
            'email.email' => 'Escribe un correo electrónico válido.',
            'email.unique' => 'Ya existe un cliente con ese correo electrónico.',
            'credit_limit.min' => 'El límite de crédito no puede ser negativo.',
            'client_uuid.uuid' => 'El identificador de la operación no es válido.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'company_name' => 'razón social',
            'email' => 'correo electrónico',
            'phone' => 'teléfono',
            'tax_id' => 'RFC',
            'address' => 'domicilio',
            'address.street' => 'calle',
            'address.exterior_number' => 'número exterior',
            'address.interior_number' => 'número interior',
            'address.neighborhood' => 'colonia',
            'address.zip_code' => 'código postal',
            'address.city' => 'ciudad',
            'address.state' => 'estado',
            'credit_limit' => 'límite de crédito',
        ];
    }
}
