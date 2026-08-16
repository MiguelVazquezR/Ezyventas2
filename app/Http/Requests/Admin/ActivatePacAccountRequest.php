<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the credentials an admin enters to activate a "normal"
 * PAC account provided by the reseller (Conectia).
 */
class ActivatePacAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // CheckSuperAdmin middleware handles access
    }

    public function rules(): array
    {
        return [
            'login_email' => ['required', 'email', 'max:255'],
            'password'    => ['required', 'string', 'min:1', 'max:255'],
            'is_shared'   => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'login_email.required' => 'El correo de la cuenta es obligatorio.',
            'login_email.email'    => 'El correo debe tener un formato válido.',
            'password.required'    => 'La contraseña es obligatoria.',
            'is_shared.boolean'    => 'El indicador de cuenta compartida debe ser verdadero o falso.',
        ];
    }
}
