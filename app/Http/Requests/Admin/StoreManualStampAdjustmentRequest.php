<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreManualStampAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $mode = $this->input('mode', 'manual');

        $rules = [
            'fiscal_profile_id' => ['required', 'integer', 'exists:fiscal_profiles,id'],
            'stamp_quantity'    => ['required', 'integer', 'min:1', 'max:999999'],
            'mode'              => ['required', Rule::in(['manual', 'purchase'])],
        ];

        if ($mode === 'manual') {
            $rules['adjustment_type'] = ['required', Rule::in(['add', 'remove'])];
            $rules['admin_note']      = ['required', 'string', 'max:1000'];
        }

        if ($mode === 'purchase') {
            $rules['proof_file'] = ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']; // 5 MB max
        }

        return $rules;
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
            'proof_file.required'        => 'El comprobante de pago es obligatorio.',
            'proof_file.file'            => 'El comprobante debe ser un archivo válido.',
            'proof_file.mimes'           => 'El comprobante debe ser PDF, JPG o PNG.',
            'proof_file.max'             => 'El comprobante no debe exceder 5 MB.',
        ];
    }
}
