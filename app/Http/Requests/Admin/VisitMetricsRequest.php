<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class VisitMetricsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Access is already restricted by the CheckSuperAdmin middleware.
        return true;
    }

    public function rules(): array
    {
        return [
            'period' => ['nullable', 'in:day,week,month'],
        ];
    }
}
