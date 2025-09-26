<?php

namespace App\Exports;

use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class ProductsExport implements FromArray, WithHeadings, ShouldAutoSize
{
    /**
    * @return array
    */
    public function array(): array
    {
        $products = Product::whereHas('branch.subscription', function ($query) {
            $query->where('id', Auth::user()->branch->subscription_id);
        })
        ->whereNull('global_product_id')
        ->with(['category', 'brand', 'provider', 'productAttributes'])
        ->get();

        $data = [];

        foreach ($products as $product) {
            if ($product->productAttributes->isNotEmpty()) {
                // Si el producto tiene variantes, añadir una fila por cada una
                foreach ($product->productAttributes as $attribute) {
                    $data[] = [
                        $product->id,
                        $product->name,
                        $product->sku,
                        implode(', ', $attribute->attributes), // Atributos
                        $product->sku . '-' . $attribute->sku_suffix, // SKU de la variante
                        $attribute->current_stock, // Stock de la variante
                        (float)$product->selling_price + (float)$attribute->selling_price_modifier, // Precio de la variante
                        $product->category->name ?? 'N/A',
                        $product->brand->name ?? 'N/A',
                    ];
                }
            } else {
                // Si es un producto simple, añadir una sola fila
                $data[] = [
                    $product->id,
                    $product->name,
                    $product->sku,
                    'N/A', // Atributos
                    'N/A', // SKU de la variante
                    $product->current_stock, // Stock del producto
                    $product->selling_price, // Precio del producto
                    $product->category->name ?? 'N/A',
                    $product->brand->name ?? 'N/A',
                ];
            }
        }

        return $data;
    }

    public function headings(): array
    {
        return [
            'ID Producto Padre',
            'Nombre',
            'SKU Padre',
            'Atributos',
            'SKU Variante',
            'Stock Variante',
            'Precio Variante',
            'Categoría',
            'Marca',
        ];
    }
}