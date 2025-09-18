<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductStockController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Product $product)
    {
        $validated = $request->validate([
            'type' => 'required|in:simple,variant',
            'quantity' => 'required_if:type,simple|integer|min:1',
            'variants' => 'required_if:type,variant|array',
            'variants.*.id' => 'required|exists:product_attributes,id',
            'variants.*.quantity' => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($validated, $product) {
            if ($validated['type'] === 'simple') {
                $product->increment('current_stock', $validated['quantity']);
                activity()
                    ->event('updated')
                    ->performedOn($product)
                    ->causedBy(auth()->user())
                    ->log("Se dio entrada de {$validated['quantity']} unidades al inventario.");
            } else {
                $totalAdded = 0;
                $changes = [];

                foreach ($validated['variants'] as $variantData) {
                    if ($variantData['quantity'] > 0) {
                        $attribute = $product->productAttributes()->find($variantData['id']);
                        if ($attribute) {
                            $attribute->increment('current_stock', $variantData['quantity']);
                            $totalAdded += $variantData['quantity'];
                            $variantName = implode(' / ', $attribute->attributes);
                            $changes[$variantName] = "+{$variantData['quantity']}";
                        }
                    }
                }

                if ($totalAdded > 0) {
                    $product->increment('current_stock', $totalAdded);
                    activity()
                        ->event('updated')
                        ->performedOn($product)
                        ->causedBy(auth()->user())
                        ->withProperties(['attributes' => $changes])
                        ->log("Se dio entrada de {$totalAdded} unidades al inventario de variantes.");
                }
            }
        });

        return redirect()->route('products.show', $product->id)->with('success', 'Stock actualizado con éxito.');
    }

    /**
     * Da entrada de stock a múltiples productos.
     */
    public function batchStore(Request $request)
    {
        $validated = $request->validate([
            'products' => 'required|array',
            'products.*.id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['products'] as $productData) {
                if ($productData['quantity'] > 0) {
                    $product = Product::find($productData['id']);
                    // Aquí se puede añadir una autorización para asegurar que el producto pertenece al usuario

                    $product->increment('current_stock', $productData['quantity']);

                    activity()
                        ->performedOn($product)
                        ->causedBy(auth()->user())
                        ->log("Se dio entrada masiva de {$productData['quantity']} unidades.");
                }
            }
        });

        return redirect()->route('products.index')->with('success', 'Stock actualizado con éxito.');
    }
}
