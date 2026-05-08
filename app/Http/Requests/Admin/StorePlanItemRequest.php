<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use App\Enums\PlanItemType;
use Illuminate\Validation\Rule;

class StorePlanItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Se asume que el middleware de rutas ya verificó los permisos de SuperAdmin
        return true; 
    }

    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:255', 'unique:plan_items,key'],
            'type' => ['required', Rule::enum(PlanItemType::class)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'monthly_price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'meta' => ['nullable', 'array'],
            // Reglas condicionales para asegurar congruencia de datos
            'meta.icon' => ['nullable', 'string', 'max:50'],
            'meta.quantity' => ['nullable', 'integer', 'min:1'],
        ];
    }
}