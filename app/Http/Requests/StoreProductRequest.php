<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Asumimos que si el usuario está logueado, puede crear productos.
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Información General
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sku' => 'nullable|string|max:255|unique:products,sku',
            'category_id' => 'required|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',

            // Precios
            'cost_price' => 'nullable|numeric|min:0',
            'provider_id' => 'nullable|exists:providers,id',
            'selling_price' => 'required|numeric|min:0',
            
            // Inventario y Variantes
            'product_type' => 'required|in:simple,variant',
            'current_stock' => 'required_if:product_type,simple|nullable|integer|min:0',
            'min_stock' => 'nullable|integer|min:0',
            'max_stock' => 'nullable|integer|min:0',
            'measure_unit' => 'required|string|max:50',

            // Variantes (la matriz llega como un string JSON)
            'variants_matrix' => 'required_if:product_type,variant|array',

            // Imágenes
            'general_images' => 'nullable|array|max:5',
            'general_images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'variant_images' => 'nullable|array',
            'variant_images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:2048',

            // Tienda en Línea
            'show_online' => 'boolean',
            'online_price' => 'nullable|numeric|min:0',
            'requires_shipping' => 'boolean',
            'weight' => 'nullable|numeric|min:0',
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'tags' => 'nullable|array',
        ];
    }
}