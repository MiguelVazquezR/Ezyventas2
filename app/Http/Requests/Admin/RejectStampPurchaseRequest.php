<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RejectStampPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Superadmin middleware handles access
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'rejection_reason.required' => 'El motivo de rechazo es obligatorio.',
            'rejection_reason.max'      => 'El motivo no debe exceder 1000 caracteres.',
        ];
    }
}
