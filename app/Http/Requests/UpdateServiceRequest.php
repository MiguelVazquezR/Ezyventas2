<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'required|exists:categories,id',
            'show_online' => 'boolean',
            'image' => 'nullable|image|max:10240',
            'remove_image' => 'nullable|boolean',
            'sat_product_code' => 'nullable|string|max:8',
            'sat_unit_code' => 'nullable|string|max:10',

            'has_variants' => 'boolean',
            'base_price' => [
                Rule::requiredIf(fn () => ! $this->boolean('has_variants')),
                'nullable',
                'numeric',
                'min:0',
            ],
            'duration_estimate' => 'nullable|string|max:255',
            'branch_ids' => 'required|array|min:1',
            'branch_ids.*' => 'exists:branches,id',

            'variants' => 'nullable|array',
            'variants.*.id' => 'nullable',
            'variants.*.name' => 'required_with:variants|string|max:255',
            'variants.*.price' => 'required_with:variants|numeric|min:0',
            'variants.*.duration_estimate' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'image.image' => 'La imagen debe ser un archivo de imagen válido.',
            'image.max' => 'La imagen no debe superar los 10 MB.',
        ];
    }
}
