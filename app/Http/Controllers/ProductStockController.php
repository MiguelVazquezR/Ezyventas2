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
            'operation' => 'required|in:entry,exit',
            'reason' => 'required|string',
            'quantity' => 'required_if:type,simple|integer|min:1',
            'variants' => 'required_if:type,variant|array',
            'variants.*.id' => 'required|exists:product_attributes,id',
            'variants.*.quantity' => 'required|integer|min:0',
        ]);

        $operation = $validated['operation'];
        $reason = $validated['reason'];

        DB::transaction(function () use ($validated, $product, $operation, $reason) {
            if ($validated['type'] === 'simple') {
                $quantity = $validated['quantity'];
                
                // 1. Capturar valor anterior
                $oldStock = $product->current_stock;
                
                // 2. Desactivar el log automático para esta operación y evitar duplicados
                $product->disableLogging();

                if ($operation === 'entry') {
                    $product->increment('current_stock', $quantity);
                } else {
                    $product->decrement('current_stock', $quantity);
                }
                
                // 3. Calcular nuevo valor
                $newStock = ($operation === 'entry') ? $oldStock + $quantity : $oldStock - $quantity;

                // 4. Crear UN SOLO registro manual con toda la información
                // Usamos el evento 'updated' para que el frontend detecte que es un cambio y muestre el DiffViewer
                activity()
                    ->event('updated') 
                    ->performedOn($product)
                    ->causedBy(auth()->user())
                    ->withProperties([
                        'old' => ['current_stock' => $oldStock],        // Para que se vea "14 ->"
                        'attributes' => ['current_stock' => $newStock], // Para que se vea "-> 15"
                        'quantity' => $quantity,
                        'operation' => $operation,
                        'reason' => $reason // El motivo que queremos mostrar
                    ])
                    ->log($operation === 'entry' ? "Entrada de {$quantity} unidades" : "Salida de {$quantity} unidades");

            } else {
                // Lógica para Variantes
                $totalChanged = 0;
                $changes = [];

                foreach ($validated['variants'] as $variantData) {
                    if ($variantData['quantity'] > 0) {
                        $attribute = $product->productAttributes()->find($variantData['id']);
                        if ($attribute) {
                            $qty = $variantData['quantity'];
                            $variantName = implode(' / ', $attribute->attributes);

                            if ($operation === 'entry') {
                                $attribute->increment('current_stock', $qty);
                                $changes[$variantName] = "+{$qty}";
                            } else {
                                $attribute->decrement('current_stock', $qty);
                                $changes[$variantName] = "-{$qty}";
                            }
                            $totalChanged += $qty;
                        }
                    }
                }

                if ($totalChanged > 0) {
                    // Recalcular el stock total
                    $newTotal = $product->productAttributes()->sum('current_stock');
                    
                    // Desactivar log automático al actualizar el total del padre
                    $product->disableLogging();
                    $product->update(['current_stock' => $newTotal]);

                    $actionWord = $operation === 'entry' ? 'entrada' : 'salida';
                    
                    activity()
                        ->event('updated')
                        ->performedOn($product)
                        ->causedBy(auth()->user())
                        ->withProperties([
                            'attributes' => $changes, // Muestra el desglose de variantes modificadas
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
     * Da entrada o salida de stock a múltiples productos.
     */
    public function batchStore(Request $request)
    {
        $validated = $request->validate([
            'products' => 'required|array',
            'products.*.id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:0',
            'operation' => 'required|in:entry,exit',
            'reason' => 'required|string',
        ]);

        $operation = $validated['operation'];
        $reason = $validated['reason'];

        DB::transaction(function () use ($validated, $operation, $reason) {
            foreach ($validated['products'] as $productData) {
                if ($productData['quantity'] > 0) {
                    $product = Product::find($productData['id']);
                    $qty = $productData['quantity'];
                    $oldStock = $product->current_stock;

                    // Desactivar log automático
                    $product->disableLogging();

                    if ($operation === 'entry') {
                        $product->increment('current_stock', $qty);
                        $msg = "Entrada masiva de {$qty} unidades.";
                    } else {
                        $product->decrement('current_stock', $qty);
                        $msg = "Salida masiva de {$qty} unidades.";
                    }
                    
                    $newStock = ($operation === 'entry') ? $oldStock + $qty : $oldStock - $qty;

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
        });

        return redirect()->route('products.index')->with('success', 'Stock masivo actualizado con éxito.');
    }
}