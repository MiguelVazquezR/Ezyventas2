<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreManualStampAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Superadmin access is already enforced by CheckSuperAdmin middleware
        return true;
    }

    public function rules(): array
    {
        return [
            'fiscal_profile_id' => ['required', 'integer', 'exists:fiscal_profiles,id'],
            'stamp_quantity'    => ['required', 'integer', 'min:1', 'max:999999'],
            'adjustment_type'   => ['required', Rule::in(['add', 'remove'])],
            'admin_note'        => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'fiscal_profile_id.required' => 'El perfil fiscal es obligatorio.',
            'stamp_quantity.required'    => 'La cantidad de timbres es obligatoria.',
            'stamp_quantity.min'         => 'La cantidad mínima es 1 timbre.',
            'adjustment_type.required'   => 'El tipo de ajuste es obligatorio.',
            'admin_note.required'        => 'El motivo del ajuste es obligatorio.',
            'admin_note.max'             => 'El motivo no debe exceder 1000 caracteres.',
        ];
    }
}
