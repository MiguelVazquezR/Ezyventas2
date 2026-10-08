<?php

namespace App\Http\Requests\Api\V1\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Switching the working branch of the user.
 */
class SwitchBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('system.branches.switch') ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
