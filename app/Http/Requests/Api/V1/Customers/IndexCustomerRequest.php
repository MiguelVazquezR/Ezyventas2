<?php

namespace App\Http\Requests\Api\V1\Customers;

use App\Http\Requests\Api\V1\Concerns\AuthorizesAnyPermission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Customer search used by the POS and the customers module.
 */
class IndexCustomerRequest extends FormRequest
{
    use AuthorizesAnyPermission;

    public function authorize(): bool
    {
        return $this->canAnyPermission(['pos.access', 'customers.access', 'customers.see_details']);
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'per_page.max' => 'No puedes pedir más de 100 clientes por página.',
        ];
    }

    public function search(): ?string
    {
        $search = $this->validated('search');

        return $search !== null ? trim((string) $search) : null;
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 20);
    }
}
