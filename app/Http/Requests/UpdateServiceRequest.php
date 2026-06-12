<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'image' => 'nullable|image',

            'has_variants' => 'boolean',
            'base_price' => 'nullable|numeric|min:0',
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
}
