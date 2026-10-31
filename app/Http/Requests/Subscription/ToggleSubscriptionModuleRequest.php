<?php

namespace App\Http\Requests\Subscription;

use App\Enums\PlanItemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ToggleSubscriptionModuleRequest extends FormRequest
{
    /**
     * Only the account owner (a user without roles) manages the plan modules.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && ! $this->user()->roles()->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'module_key' => [
                'required',
                'string',
                Rule::exists('plan_items', 'key')->where(
                    fn ($query) => $query
                        ->where('type', PlanItemType::MODULE->value)
                        ->where('is_active', true)
                ),
            ],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'module_key.required' => 'Selecciona un módulo válido.',
            'module_key.exists' => 'El módulo seleccionado no está disponible.',
            'is_active.required' => 'Indica si el módulo debe activarse o desactivarse.',
            'is_active.boolean' => 'El estado del módulo no es válido.',
        ];
    }
}
