<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductStockController extends Controller
{
    /**
     * Store a newly created resource in storage (Single Product Mode).
     */
    public function store(Request $request, Product $product)
    {
        $validated = $request->validate([
            'type' => 'required|in:simple,variant',
            'operation' => 'required|in:entry,exit',
            'reason' => 'required|string',
            'quantity' => 'required_if:type,simple|integer|min:1',
            'variants' => 'required_if:type,variant|array',
            'variants.*.id' => 'required|exists:product_attributes,id',
            'variants.*.quantity' => 'required|integer|min:0',
        ]);

        $operation = $validated['operation'];
        $reason = $validated['reason'];
        $branchId = auth()->user()->branch_id; // Stock por sucursal

        DB::transaction(function () use ($validated, $product, $operation, $reason, $branchId) {
            
            // 1. PRODUCTO SIMPLE
            if ($validated['type'] === 'simple') {
                $quantity = $validated['quantity'];
                
                // Actualizar la tabla pivot de la sucursal, no la tabla general
                $pivot = $product->branches()->where('branches.id', $branchId)->first()?->pivot;
                if (!$pivot) return; // Si no está asignado a la sucursal, ignorar
                
                $oldStock = (float) $pivot->current_stock;
                $newStock = ($operation === 'entry') ? $oldStock + $quantity : $oldStock - $quantity;
                
                $pivot->current_stock = $newStock;
                $pivot->save();

                $product->disableLogging();

                activity()
                    ->event('updated') 
                    ->performedOn($product)
                    ->causedBy(auth()->user())
                    ->withProperties([
                        'old' => ['current_stock' => $oldStock],
                        'attributes' => ['current_stock' => $newStock],
                        'quantity' => $quantity,
                        'operation' => $operation,
                        'reason' => $reason
                    ])
                    ->log($operation === 'entry' ? "Entrada de {$quantity} unidades" : "Salida de {$quantity} unidades");

            } 
            // 2. PRODUCTO CON VARIANTES
            else {
                $totalChanged = 0;
                $changes = [];

                foreach ($validated['variants'] as $variantData) {
                    if ($variantData['quantity'] > 0) {
                        $attribute = $product->productAttributes()->find($variantData['id']);
                        
                        if ($attribute) {
                            // Actualizar la tabla pivot de la variante y la sucursal
                            $vPivot = $attribute->branches()->where('branches.id', $branchId)->first()?->pivot;
                            
                            if ($vPivot) {
                                $qty = $variantData['quantity'];
                                $variantName = implode(' / ', $attribute->attributes);

                                $newStock = ($operation === 'entry') ? $vPivot->current_stock + $qty : $vPivot->current_stock - $qty;
                                $vPivot->current_stock = $newStock;
                                $vPivot->save();

                                $changes[$variantName] = ($operation === 'entry' ? '+' : '-') . $qty;
                                $totalChanged += $qty;
                            }
                        }
                    }
                }

                if ($totalChanged > 0) {
                    $product->disableLogging();
                    $actionWord = $operation === 'entry' ? 'entrada' : 'salida';
                    
                    activity()
                        ->event('updated')
                        ->performedOn($product)
                        ->causedBy(auth()->user())
                        ->withProperties([
                            'attributes' => $changes, 
                            'operation' => $operation,
                            'reason' => $reason
                        ])
                        ->log("Se dio {$actionWord} de {$totalChanged} unidades (variantes)");
                }
            }
        });

        return redirect()->back()->with('success', 'Inventario actualizado correctamente.');
    }

    /**
     * Da entrada o salida de stock a múltiples productos (Batch Mode).
     */
    public function batchStore(Request $request)
    {
        $validated = $request->validate([
            'products' => 'required|array',
            'products.*.id' => 'required|exists:products,id',
            'products.*.is_variant' => 'required|boolean',
            'products.*.quantity' => 'nullable|integer|min:0',
            'products.*.variants' => 'nullable|array',
            'products.*.variants.*.id' => 'nullable|exists:product_attributes,id',
            'products.*.variants.*.quantity' => 'nullable|integer|min:0',
            'operation' => 'required|in:entry,exit',
            'reason' => 'required|string',
        ]);

        $operation = $validated['operation'];
        $reason = $validated['reason'];
        $branchId = auth()->user()->branch_id; // Stock por sucursal

        DB::transaction(function () use ($validated, $operation, $reason, $branchId) {
            foreach ($validated['products'] as $productData) {
                
                $product = Product::find($productData['id']);
                if (!$product) continue;
                
                $product->disableLogging();

                // 1. SI EL PRODUCTO DEL BATCH ES SIMPLE
                if (!$productData['is_variant']) {
                    $qty = $productData['quantity'] ?? 0;
                    
                    if ($qty > 0) {
                        $pivot = $product->branches()->where('branches.id', $branchId)->first()?->pivot;
                        
                        if ($pivot) {
                            $oldStock = (float) $pivot->current_stock;
                            $newStock = ($operation === 'entry') ? $oldStock + $qty : $oldStock - $qty;
                            
                            $pivot->current_stock = $newStock;
                            $pivot->save();

                            $msg = ($operation === 'entry') ? "Entrada masiva de {$qty} unidades." : "Salida masiva de {$qty} unidades.";

                            activity()
                                ->event('updated')
                                ->performedOn($product)
                                ->causedBy(auth()->user())
                                ->withProperties([
                                    'old' => ['current_stock' => $oldStock],
                                    'attributes' => ['current_stock' => $newStock],
                                    'quantity' => $qty,
                                    'operation' => $operation,
                                    'reason' => $reason
                                ])
                                ->log($msg);
                        }
                    }
                } 
                // 2. SI EL PRODUCTO DEL BATCH TIENE VARIANTES
                else {
                    $totalChanged = 0;
                    $changes = [];

                    if (!empty($productData['variants'])) {
                        foreach ($productData['variants'] as $vData) {
                            $qty = $vData['quantity'] ?? 0;
                            
                            if ($qty > 0) {
                                $attribute = $product->productAttributes()->find($vData['id']);
                                
                                if ($attribute) {
                                    $vPivot = $attribute->branches()->where('branches.id', $branchId)->first()?->pivot;
                                    
                                    if ($vPivot) {
                                        $variantName = implode(' / ', $attribute->attributes);
                                        $newStock = ($operation === 'entry') ? $vPivot->current_stock + $qty : $vPivot->current_stock - $qty;
                                        
                                        $vPivot->current_stock = $newStock;
                                        $vPivot->save();

                                        $changes[$variantName] = ($operation === 'entry' ? '+' : '-') . $qty;
                                        $totalChanged += $qty;
                                    }
                                }
                            }
                        }
                    }

                    if ($totalChanged > 0) {
                        $actionWord = $operation === 'entry' ? 'entrada' : 'salida';
                        activity()
                            ->event('updated')
                            ->performedOn($product)
                            ->causedBy(auth()->user())
                            ->withProperties([
                                'attributes' => $changes,
                                'operation' => $operation,
                                'reason' => $reason
                            ])
                            ->log("Se dio {$actionWord} masiva de {$totalChanged} unidades (variantes)");
                    }
                }
            }
        });

        return redirect()->route('products.index')->with('success', 'Stock actualizado con éxito en la sucursal.');
    }
}