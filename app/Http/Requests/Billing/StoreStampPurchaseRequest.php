<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStampPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('stamps.purchase');
    }

    public function rules(): array
    {
        return [
            'fiscal_profile_id' => ['required', 'integer', 'exists:fiscal_profiles,id'],
            'stamp_quantity'    => ['required', 'integer', 'min:1', 'max:999999'],
            'payment_method'    => ['required', Rule::in(['mercadopago', 'bank_transfer'])],
            'proof_file'        => [
                Rule::requiredIf(fn () => $this->input('payment_method') === 'bank_transfer'),
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:10240',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'fiscal_profile_id.required' => 'El perfil fiscal es obligatorio.',
            'fiscal_profile_id.exists'   => 'El perfil fiscal seleccionado no existe.',
            'stamp_quantity.required'    => 'La cantidad de timbres es obligatoria.',
            'stamp_quantity.min'         => 'La cantidad mínima es 1 timbre.',
            'payment_method.required'    => 'El método de pago es obligatorio.',
            'proof_file.required'        => 'El comprobante de transferencia es obligatorio.',
            'proof_file.mimes'           => 'El comprobante debe ser JPG, PNG o PDF.',
            'proof_file.max'             => 'El comprobante no debe exceder 10 MB.',
        ];
    }
}
