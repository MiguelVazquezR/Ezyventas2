<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Query\Builder;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productId = $this->route('product')->id;
        $user = Auth::user();

        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sku' => [
                'nullable', 
                'string', 
                'max:255', 
                // Validar unicidad por Branch, ignorando el producto actual
                Rule::unique('products')->ignore($productId)->where(function (Builder $query) use ($user) {
                    return $query->where('branch_id', $user->branch_id);
                }),
            ],
            'location' => 'nullable|string|max:255', 

            'branch_ids' => 'required|array|min:1',
            'branch_ids.*' => 'exists:branches,id',

            'category_id' => 'required|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'cost_price' => 'nullable|numeric|min:0',
            'provider_id' => 'nullable|exists:providers,id',
            'selling_price' => 'required|numeric|min:0',
            'show_in_pos' => 'boolean',
            
            'price_tiers' => 'nullable|array',
            'price_tiers.*.min_quantity' => [
                'required',
                'integer',
                'min:2', 
                'distinct' 
            ],
            'price_tiers.*.price' => [
                'required',
                'numeric',
                'min:0.01'
            ],
            'tax_rate' => 'nullable|numeric',
            
            // Inventario y Variantes
            'product_type' => 'required|in:simple,variant',
            'current_stock' => 'required_if:product_type,simple|nullable|numeric|min:0',
            'min_stock' => 'nullable|numeric|min:0',
            'max_stock' => 'nullable|numeric|min:0',
            'measure_unit' => 'required|string|max:50',
            
            // Variantes
            'variants_matrix' => 'required_if:product_type,variant|array',
            'variants_matrix.*.id' => 'nullable|integer',
            'variants_matrix.*.attributes' => 'required|array',
            'variants_matrix.*.sku' => 'nullable|string|max:255',
            'variants_matrix.*.location' => 'nullable|string|max:255',
            'variants_matrix.*.current_stock' => 'nullable|numeric|min:0',
            'variants_matrix.*.min_stock' => 'nullable|numeric|min:0', // NUEVO
            'variants_matrix.*.max_stock' => 'nullable|numeric|min:0', // NUEVO
            'variants_matrix.*.selling_price_modifier' => 'nullable|numeric',
            
            // Imágenes
            'general_images' => 'nullable|array|max:5',
            'general_images.*' => 'image|mimes:jpeg,png,jpg,gif,webp,avif',
            'variant_images' => 'nullable|array',
            'variant_images.*' => 'image|mimes:jpeg,png,jpg,gif,webp,avif',
            'deleted_media_ids' => 'nullable|array', 
            'deleted_media_ids.*' => 'integer|exists:media,id',
            
            // Tienda en Línea
            'show_online' => 'sometimes|boolean',
            'online_price' => 'nullable|numeric|min:0',
            'requires_shipping' => 'sometimes|boolean',
            'weight' => 'nullable|numeric|min:0',
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            
            // Configuraciones Adicionales
            'delivery_days' => 'nullable|integer|min:0',
            'tags' => 'nullable|string',
            'is_featured' => 'sometimes|boolean',
            'is_on_sale' => 'sometimes|boolean',
            'sale_price' => 'nullable|numeric|min:0',
            'sale_start_date' => 'nullable|date',
            'sale_end_date' => 'nullable|date|after_or_equal:sale_start_date',
        ];
    }
}