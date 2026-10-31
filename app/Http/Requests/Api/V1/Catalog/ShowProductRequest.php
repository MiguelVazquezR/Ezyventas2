<?php

namespace App\Http\Requests\Api\V1\Catalog;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A single product of the POS catalog.
 */
class ShowProductRequest extends FormRequest
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
