<?php

namespace App\Http\Requests\Api\V1\CashRegisters;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Current cash register session of the authenticated user.
 */
class CurrentSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pos.access') ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
