<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFiscalProfileRequest extends FormRequest
{
    /**
     * Only users with invoicing settings access can update fiscal profiles.
     */
    public function authorize(): bool
    {
        return $this->user()->can('invoices.settings.access');
    }

    /**
     * Validation rules for editing an existing FiscalProfile.
     *
     * Only the data fields are editable — the RFC is the identity that links
     * the profile to the PAC account and its CSD certificates, so it is not
     * included here (it stays immutable in edit mode).
     */
    public function rules(): array
    {
        return [
            'rfc' => [
                'required', 'string', 'min:12', 'max:13',
                Rule::unique('fiscal_profiles', 'rfc')->ignore($this->route('fiscalProfile')->id),
            ],
            'razon_social' => [
                'required', 'string', 'max:255',
            ],
            'regimen_fiscal' => [
                'required', 'string', 'max:10',
            ],
            'postal_code' => [
                'required', 'string', 'size:5',
            ],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('fiscal_profiles', 'email')->ignore($this->route('fiscalProfile')->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'rfc.required'            => 'El RFC es obligatorio.',
            'rfc.min'                 => 'El RFC debe tener al menos 12 caracteres.',
            'rfc.max'                 => 'El RFC no debe exceder 13 caracteres.',
            'rfc.unique'              => 'Este RFC ya está registrado en otro perfil fiscal.',
            'razon_social.required'   => 'La razón social es obligatoria.',
            'razon_social.max'        => 'La razón social no debe exceder 255 caracteres.',
            'regimen_fiscal.required' => 'El régimen fiscal es obligatorio.',
            'postal_code.required'    => 'El código postal es obligatorio.',
            'postal_code.size'        => 'El código postal debe tener 5 dígitos.',
            'email.required'          => 'El email de contacto fiscal es obligatorio.',
            'email.email'             => 'El email debe tener un formato válido.',
            'email.unique'            => 'Este email ya está registrado en otro perfil fiscal.',
        ];
    }

    /**
     * Normalize postal code (trim whitespace).
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('rfc')) {
            $this->merge([
                'rfc' => strtoupper(trim($this->rfc)),
            ]);
        }
        if ($this->has('postal_code')) {
            $this->merge([
                'postal_code' => trim($this->postal_code),
            ]);
        }
    }
}
