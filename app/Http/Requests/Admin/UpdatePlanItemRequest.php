<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use App\Enums\PlanItemType;
use Illuminate\Validation\Rule;

class UpdatePlanItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // El 'key' no se valida ni se actualiza, la UI lo bloquea.
            'type' => ['required', Rule::enum(PlanItemType::class)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'monthly_price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'meta' => ['nullable', 'array'],
            'meta.icon' => ['nullable', 'string', 'max:50'],
            'meta.quantity' => ['nullable', 'integer', 'min:1'],
        ];
    }
}