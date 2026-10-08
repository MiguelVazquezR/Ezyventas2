<?php

namespace App\Http\Requests\Api\V1\CashRegisters;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Joining an open cash register session.
 */
class JoinCashRegisterSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pos.access') ?? false;
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
