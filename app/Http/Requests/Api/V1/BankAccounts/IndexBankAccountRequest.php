<?php

namespace App\Http\Requests\Api\V1\BankAccounts;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bank accounts available to the user when opening the cash register.
 */
class IndexBankAccountRequest extends FormRequest
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
