<?php

namespace App\Http\Requests\Api\V1\Dashboard;

use App\Services\Dashboard\DashboardAlertService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Window of the "Ventas por vencer" list of the home screen.
 */
class ExpiringLayawaysRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('dashboard.see_layaways') ?? false;
    }

    public function rules(): array
    {
        return [
            'days' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ];
    }

    /**
     * The window is always present in the validated data, so the controller
     * never has to guess the default.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'days' => $this->input('days', DashboardAlertService::DEFAULT_WINDOW_DAYS),
        ]);
    }

    public function messages(): array
    {
        return [
            'days.integer' => 'El número de días debe ser un valor entero.',
            'days.min' => 'El número de días debe ser al menos 1.',
            'days.max' => 'El número de días no puede ser mayor a 30.',
        ];
    }
}
