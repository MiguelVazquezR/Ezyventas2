<?php

namespace App\Http\Requests\Api\V1\CashRegisters;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Retakes a shift on a terminal without asking for the cash fund again.
 */
class RejoinOrStartCashRegisterSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pos.access') ?? false;
    }

    public function rules(): array
    {
        return [
            'cash_register_id' => ['required', 'integer', 'exists:cash_registers,id'],
            'original_opener_id' => ['required', 'integer', 'exists:users,id'],
            // Idempotency key of the offline queue (used from phase 5 on).
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'cash_register_id.required' => 'Indica la caja que vas a retomar.',
            'cash_register_id.exists' => 'La caja seleccionada no existe.',
            'original_opener_id.required' => 'Indica quién abrió la caja originalmente.',
            'original_opener_id.exists' => 'El usuario indicado no existe.',
            'client_uuid.uuid' => 'El identificador de la operación no es válido.',
        ];
    }

    public function attributes(): array
    {
        return [
            'cash_register_id' => 'caja',
            'original_opener_id' => 'usuario que abrió la caja',
        ];
    }
}
