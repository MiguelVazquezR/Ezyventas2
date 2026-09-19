<?php

namespace App\Http\Requests\Api\V1\ServiceOrders;

use App\Enums\ServiceOrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters of the service orders work list.
 */
class IndexServiceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('services.orders.access') ?? false;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(ServiceOrderStatus::class)],
            'sortField' => ['nullable', Rule::in(['received_at', 'promised_at', 'folio', 'final_total'])],
            'sortOrder' => ['nullable', Rule::in(['asc', 'desc'])],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.enum' => 'El estatus seleccionado no es válido.',
            'sortField.in' => 'No se puede ordenar por ese campo.',
            'per_page.max' => 'No puedes pedir más de 100 órdenes por página.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'search' => $this->validated('search'),
            'status' => $this->validated('status'),
            'updated_since' => $this->validated('updated_since'),
            'sort_field' => $this->validated('sortField'),
            'sort_order' => $this->validated('sortOrder'),
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 20);
    }
}
