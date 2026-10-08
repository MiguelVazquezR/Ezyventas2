<?php

namespace App\Http\Requests\Api\V1\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Close other sessions": the password confirms the action and every other
 * device (and web session) is signed out.
 */
class LogoutOtherDevicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.required' => 'Escribe tu contraseña para confirmar.',
        ];
    }

    public function attributes(): array
    {
        return ['password' => 'contraseña'];
    }
}
