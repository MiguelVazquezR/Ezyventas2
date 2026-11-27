<?php

namespace App\Http\Requests\HelpCenter;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveHelpArticleRequest extends FormRequest
{
    /**
     * The admin route group is already protected by the CheckSuperAdmin middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'direction' => ['required', 'string', Rule::in(['up', 'down'])],
        ];
    }
}