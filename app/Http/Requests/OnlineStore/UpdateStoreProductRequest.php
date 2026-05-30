<?php

namespace App\Http\Requests\OnlineStore;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('online_store.products.edit');
    }

    public function rules(): array
    {
        return [
            'online_price' => ['nullable', 'numeric', 'min:0'],
            'show_online' => ['boolean'],
            'is_featured' => ['boolean'],
            'store_sort_order' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'image', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'online_price.min' => 'The online price must be at least 0.',
        ];
    }
}
