<?php

namespace App\Http\Requests\Api\V1\ServiceOrders;

use App\Enums\ServiceOrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Status change of a service order.
 */
class UpdateServiceOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('services.orders.change_status') ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ServiceOrderStatus::class)],
            // Idempotency key of the offline queue (used from phase 5 on).
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Selecciona el nuevo estatus de la orden.',
            'status.enum' => 'El estatus seleccionado no es válido.',
            'client_uuid.uuid' => 'El identificador de la operación no es válido.',
        ];
    }

    public function status(): ServiceOrderStatus
    {
        return ServiceOrderStatus::from((string) $this->validated('status'));
    }
}
