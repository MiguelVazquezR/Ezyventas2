<?php

namespace App\Http\Requests\Api\V1\ServiceOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Repairs an old order that has no linked sale, so it can be charged.
 */
class EnsureServiceOrderTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('services.orders.edit') ?? false;
    }

    public function rules(): array
    {
        return [
            // Idempotency key of the offline queue (used from phase 5 on).
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'client_uuid.uuid' => 'El identificador de la operación no es válido.',
        ];
    }
}
