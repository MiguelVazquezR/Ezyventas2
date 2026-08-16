<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class AdjustLayawayStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('products.edit');
    }

    public function rules(): array
    {
        return [
            'reserved_stock' => 'nullable|numeric|min:0',
            'available_stock' => 'nullable|numeric|min:0',
            'variants' => 'nullable|array',
            'variants.*.id' => 'required|exists:product_attributes,id',
            'variants.*.reserved_stock' => 'nullable|numeric|min:0',
            'variants.*.available_stock' => 'nullable|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'reserved_stock.min' => 'La cantidad de apartados no puede ser negativa.',
            'available_stock.min' => 'La cantidad disponible no puede ser negativa.',
            'variants.*.id.exists' => 'Una de las variantes seleccionadas no existe.',
            'variants.*.reserved_stock.min' => 'La cantidad de apartados de la variante no puede ser negativa.',
            'variants.*.available_stock.min' => 'La cantidad disponible de la variante no puede ser negativa.',
        ];
    }
}