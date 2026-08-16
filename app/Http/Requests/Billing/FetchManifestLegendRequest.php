<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class FetchManifestLegendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('invoices.settings.access');
    }

    public function rules(): array
    {
        return [
            'cer_file' => [
                'required',
                'file',
                'extensions:cer',
                'max:1024',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'cer_file.required' => 'El certificado (.cer) de tu FIEL es obligatorio.',
            'cer_file.extensions'    => 'El archivo debe tener extensión .cer.',
            'cer_file.max'      => 'El archivo .cer no debe exceder 1 MB.',
        ];
    }
}
