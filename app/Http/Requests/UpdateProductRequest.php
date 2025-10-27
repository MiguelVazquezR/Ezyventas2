<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productId = $this->route('product')->id;

        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sku' => ['nullable', 'string', 'max:255', Rule::unique('products')->ignore($productId)],
            'category_id' => 'required|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'cost_price' => 'nullable|numeric|min:0',
            'provider_id' => 'nullable|exists:providers,id',
            'selling_price' => 'required|numeric|min:0',
            'price_tiers' => 'nullable|array',
            'price_tiers.*.min_quantity' => [
                'required',
                'integer',
                'min:2', // El min 1 es el selling_price
                'distinct' // No permite dos niveles con la misma cantidad
            ],
            'price_tiers.*.price' => [
                'required',
                'numeric',
                'min:0.01'
            ],
            'tax_rate' => 'nullable|numeric',
            'product_type' => 'required|in:simple,variant',
            'current_stock' => 'required_if:product_type,simple|nullable|integer|min:0',
            'min_stock' => 'nullable|integer|min:0',
            'max_stock' => 'nullable|integer|min:0',
            'measure_unit' => 'required|string|max:50',
            'variants_matrix' => 'required_if:product_type,variant|array',
            'general_images' => 'nullable|array|max:5',
            'general_images.*' => 'image|mimes:jpeg,png,jpg,gif,webp,avif',
            'variant_images' => 'nullable|array',
            'variant_images.*' => 'image|mimes:jpeg,png,jpg,gif,webp,avif',
            'deleted_media_ids' => 'nullable|array', // Para las imágenes a eliminar
            'deleted_media_ids.*' => 'integer|exists:media,id',
            'show_online' => 'sometimes|boolean',
            'online_price' => 'nullable|numeric|min:0',
            'requires_shipping' => 'sometimes|boolean',
            'weight' => 'nullable|numeric|min:0',
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'tags' => 'nullable|array',
        ];
    }
}
