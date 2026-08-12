<?php

namespace App\Http\Controllers;

use App\Actions\Product\AdjustLayawayStockAction;
use App\Http\Requests\Product\AdjustLayawayStockRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;

class ProductLayawayStockController extends Controller
{
    public function update(AdjustLayawayStockRequest $request, Product $product, AdjustLayawayStockAction $action): RedirectResponse
    {
        $action->execute($product, $request->validated(), $request->user());

        return redirect()->route('products.show', $product->id)
            ->with('success', 'Ajuste de apartados guardado correctamente.');
    }
}