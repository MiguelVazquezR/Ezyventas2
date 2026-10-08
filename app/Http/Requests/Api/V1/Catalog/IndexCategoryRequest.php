<?php

namespace App\Http\Requests\Api\V1\Catalog;

use App\Http\Requests\Api\V1\Concerns\AuthorizesAnyPermission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Categories of the subscription. Product categories feed the POS; service
 * categories feed the service orders form, hence both permissions are valid.
 */
class IndexCategoryRequest extends FormRequest
{
    use AuthorizesAnyPermission;

    public function authorize(): bool
    {
        return $this->canAnyPermission(['pos.access', 'services.orders.access']);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'type' => $this->input('type', 'product'),
        ]);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['product', 'service'])],
        ];
    }

    public function messages(): array
    {
        return [
            'type.in' => 'El tipo de categoría debe ser "product" o "service".',
        ];
    }

    public function type(): string
    {
        return (string) $this->validated('type');
    }
}
