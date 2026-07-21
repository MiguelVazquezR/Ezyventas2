<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class SignManifestRequest extends FormRequest
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
            'key_file' => [
                'required',
                'file',
                'extensions:key',
                'max:1024',
            ],
            'password' => [
                'required',
                'string',
                'min:1',
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'cer_file.required' => 'El certificado (.cer) de tu FIEL es obligatorio.',
            'cer_file.extensions'    => 'El archivo debe tener extensión .cer.',
            'cer_file.max'      => 'El archivo .cer no debe exceder 1 MB.',
            'key_file.required' => 'La llave privada (.key) de tu FIEL es obligatoria.',
            'key_file.extensions'    => 'El archivo debe tener extensión .key.',
            'key_file.max'      => 'El archivo .key no debe exceder 1 MB.',
            'password.required' => 'La contraseña de tu FIEL es obligatoria.',
            'password.min'      => 'La contraseña de tu FIEL no puede estar vacía.',
            'email.email'       => 'El correo electrónico no es válido.',
        ];
    }
}
