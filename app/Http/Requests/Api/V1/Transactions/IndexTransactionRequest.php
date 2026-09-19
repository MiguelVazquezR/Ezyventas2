<?php

namespace App\Http\Requests\Api\V1\Transactions;

use App\Enums\TransactionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters of the sales history.
 */
class IndexTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('transactions.access') ?? false;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(TransactionStatus::class)],
            'date_start' => ['nullable', 'date'],
            'date_end' => ['nullable', 'date'],
            'sortField' => ['nullable', Rule::in(['created_at', 'folio', 'total', 'customer.name'])],
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
            'date_start.date' => 'La fecha inicial no es válida.',
            'date_end.date' => 'La fecha final no es válida.',
            'sortField.in' => 'No se puede ordenar por ese campo.',
            'per_page.max' => 'No puedes pedir más de 100 ventas por página.',
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
            'date_start' => $this->validated('date_start'),
            'date_end' => $this->validated('date_end'),
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
