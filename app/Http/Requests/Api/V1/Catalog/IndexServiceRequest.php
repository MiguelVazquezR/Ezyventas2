<?php

namespace App\Http\Requests\Api\V1\Catalog;

use App\Http\Requests\Api\V1\Concerns\AuthorizesAnyPermission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Services catalog used to build service order items.
 */
class IndexServiceRequest extends FormRequest
{
    use AuthorizesAnyPermission;

    public function authorize(): bool
    {
        return $this->canAnyPermission(['pos.access', 'services.orders.access']);
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.exists' => 'La categoría seleccionada no existe.',
            'per_page.max' => 'No puedes pedir más de 100 servicios por página.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'search' => $this->validated('search'),
            'category_id' => $this->validated('category_id'),
            'updated_since' => $this->validated('updated_since'),
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 20);
    }
}
