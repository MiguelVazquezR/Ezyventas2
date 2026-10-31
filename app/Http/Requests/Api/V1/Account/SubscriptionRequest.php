<?php

namespace App\Http\Requests\Api\V1\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base of the subscription requests: only the owner of the subscription may see
 * or edit it (the topbar hides the option for the rest of the team).
 */
class SubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && !$user->roles()->exists();
    }

    public function rules(): array
    {
        return [];
    }

    public function messages(): array
    {
        return [
            'commercial_name.required' => 'Escribe el nombre comercial del negocio.',
        ];
    }
}
