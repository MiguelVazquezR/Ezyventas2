<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Credentials sent by the mobile app to obtain a Sanctum token.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Escribe tu correo electrónico.',
            'email.email' => 'El correo electrónico no es válido.',
            'password.required' => 'Escribe tu contraseña.',
            'device_name.max' => 'El nombre del dispositivo es demasiado largo.',
            'client_uuid.uuid' => 'El identificador de la operación no es válido.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->input('email'))
                ? mb_strtolower(trim($this->input('email')))
                : $this->input('email'),
            'device_name' => $this->resolveDeviceName(),
        ]);
    }

    /**
     * Friendly name used to identify the issued token.
     */
    public function deviceName(): string
    {
        $deviceName = $this->input('device_name');

        return is_string($deviceName) && trim($deviceName) !== ''
            ? trim($deviceName)
            : 'Dispositivo móvil';
    }

    private function resolveDeviceName(): ?string
    {
        $deviceName = $this->input('device_name');

        if (is_string($deviceName) && trim($deviceName) !== '') {
            return trim($deviceName);
        }

        $userAgent = $this->userAgent();

        return $userAgent ? mb_substr($userAgent, 0, 255) : null;
    }
}
