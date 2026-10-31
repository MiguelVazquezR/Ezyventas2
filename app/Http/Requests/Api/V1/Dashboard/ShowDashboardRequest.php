<?php

namespace App\Http\Requests\Api\V1\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Home screen of the app. It takes no parameters: the branch, the subscription
 * and the permissions are derived from the token.
 */
class ShowDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [];
    }
}
