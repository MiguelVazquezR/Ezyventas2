<?php

namespace App\Http\Requests\Subscription;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReferrerBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return !$this->user()->roles()->exists();
    }

    public function rules(): array
    {
        return [
            'clabe'               => ['required', 'string', 'digits:18'],
            'bank_name'           => ['required', 'string', 'max:100'],
            'account_holder_name' => ['required', 'string', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'clabe.required'               => 'La CLABE es obligatoria.',
            'clabe.digits'                  => 'La CLABE debe tener exactamente 18 dígitos.',
            'bank_name.required'            => 'El nombre del banco es obligatorio.',
            'account_holder_name.required'  => 'El nombre del titular es obligatorio.',
        ];
    }
}
