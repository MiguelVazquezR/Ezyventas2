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
                'mimes:cer',
                'max:1024', // 1MB is more than enough for a certificate
            ],
            'key_file' => [
                'required',
                'file',
                'mimes:key',
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
            'cer_file.mimes'    => 'El archivo debe tener extensión .cer.',
            'cer_file.max'      => 'El archivo .cer no debe exceder 1 MB.',
            'key_file.required' => 'La llave privada (.key) de tu FIEL es obligatoria.',
            'key_file.mimes'    => 'El archivo debe tener extensión .key.',
            'key_file.max'      => 'El archivo .key no debe exceder 1 MB.',
            'password.required' => 'La contraseña de tu FIEL es obligatoria.',
            'email.email'       => 'El correo electrónico no es válido.',
        ];
    }
}
