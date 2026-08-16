<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class AcceptManifestTextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('invoices.settings.access');
    }

    public function rules(): array
    {
        return [
            'accepted' => [
                'required',
                'accepted',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'accepted.required' => 'Debes leer y aceptar el manifiesto antes de continuar.',
            'accepted.accepted' => 'Debes leer y aceptar el manifiesto antes de continuar.',
        ];
    }
}
