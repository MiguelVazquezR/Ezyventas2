<?php

namespace App\Http\Requests\Invoices;

use Illuminate\Foundation\Http\FormRequest;

class SaveBillingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('invoices.settings.access');
    }

    public function rules(): array
    {
        return [
            'emitter_rfc' => [
                'required',
                'string',
                'min:12',
                'max:13',
            ],
            'emitter_legal_name' => [
                'required',
                'string',
                'max:255',
            ],
            'emitter_tax_regime' => [
                'required',
                'string',
                'max:10',
            ],
            'emitter_postal_code' => [
                'required',
                'string',
                'size:5',
            ],
            'api_key' => [
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'emitter_rfc.required'          => 'El RFC del emisor es obligatorio.',
            'emitter_rfc.min'               => 'El RFC debe tener al menos 12 caracteres.',
            'emitter_rfc.max'               => 'El RFC no debe exceder 13 caracteres.',
            'emitter_legal_name.required'   => 'La razón social es obligatoria.',
            'emitter_tax_regime.required'   => 'El régimen fiscal es obligatorio.',
            'emitter_postal_code.required'  => 'El código postal es obligatorio.',
            'emitter_postal_code.size'      => 'El código postal debe tener 5 dígitos.',
        ];
    }

    /**
     * Normalize RFC and postal code before validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('emitter_rfc')) {
            $this->merge([
                'emitter_rfc' => strtoupper(trim($this->emitter_rfc)),
            ]);
        }

        if ($this->has('emitter_postal_code')) {
            $this->merge([
                'emitter_postal_code' => trim($this->emitter_postal_code),
            ]);
        }
    }
}
