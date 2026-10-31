<?php

namespace App\Http\Requests\Api\V1\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Personal data of the profile (multipart when a new photo is uploaded).
 *
 * Changing the email sends a verification code to the new address, so the user
 * must confirm it before it counts as verified.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->user()?->id),
            ],
            'photo' => ['nullable', 'image', 'max:1024'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Escribe tu nombre.',
            'email.required' => 'Escribe tu correo electrónico.',
            'email.email' => 'Escribe un correo electrónico válido.',
            'email.unique' => 'Ese correo electrónico ya está registrado.',
            'photo.image' => 'La foto debe ser una imagen.',
            'photo.max' => 'La foto debe pesar menos de 1 MB.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'email' => 'correo electrónico',
            'photo' => 'foto de perfil',
        ];
    }
}
