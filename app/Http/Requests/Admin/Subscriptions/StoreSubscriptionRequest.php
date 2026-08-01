<?php

namespace App\Http\Requests\Admin\Subscriptions;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'business_name'   => ['required', 'string', 'max:255', 'unique:subscriptions,business_name'],
            'commercial_name' => ['required', 'string', 'max:255'],
            'contact_email'   => ['nullable', 'email', 'max:255'],
            'contact_phone'   => ['nullable', 'string', 'max:20'],
            'tax_id'          => ['nullable', 'string', 'max:50'],
            'address'         => ['nullable', 'string', 'max:500'],

            'admin_name'      => ['required', 'string', 'max:255'],
            'admin_email'     => ['required', 'email', 'max:255', 'unique:users,email'],
            'admin_password'  => ['required', 'string', 'min:8'],
            'verify_email'       => ['nullable', 'boolean'],
            'complete_onboarding' => ['nullable', 'boolean'],

            'limits'          => ['required', 'array'],
            'limits.*'        => ['required', 'integer', 'min:-1'],
            'modules'         => ['required', 'array'],
            'modules.*'       => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'business_name.required'   => 'La razón social es obligatoria.',
            'business_name.unique'     => 'Esta razón social ya está registrada.',
            'commercial_name.required' => 'El nombre comercial es obligatorio.',
            'contact_email.email'      => 'El correo de contacto no es válido.',
            'admin_name.required'      => 'El nombre del administrador es obligatorio.',
            'admin_email.required'     => 'El correo del administrador es obligatorio.',
            'admin_email.email'        => 'El correo del administrador no es válido.',
            'admin_email.unique'       => 'Este correo ya está registrado por otro usuario.',
            'admin_password.required'  => 'La contraseña es obligatoria.',
            'admin_password.min'       => 'La contraseña debe tener al menos 8 caracteres.',
            'limits.*.integer'         => 'El valor del límite debe ser numérico.',
        ];
    }

    public function prepareForValidation(): void
    {
        $this->merge([
            'verify_email' => $this->boolean('verify_email'),
        ]);
    }
}