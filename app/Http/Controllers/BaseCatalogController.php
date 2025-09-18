<?php

namespace App\Http\Controllers;

use App\Models\GlobalProduct;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class BaseCatalogController extends Controller
{
    public function index(): Response
    {
        $user = Auth::user();
        $subscription = $user->branch->subscription;

        // Productos del suscriptor que ya fueron importados del catálogo
        $localCatalogProducts = Product::where('branch_id', $user->branch_id)
            ->whereNotNull('global_product_id')
            ->with('media')
            ->get();

        $importedIds = $localCatalogProducts->pluck('global_product_id');

        // Productos globales disponibles para este suscriptor (que no han sido importados)
        $availableGlobalProducts = GlobalProduct::where('business_type_id', $subscription->business_type_id)
            ->whereNotIn('id', $importedIds)
            ->with('media')
            ->get();
        
        return Inertia::render('Product/BaseCatalog', [
            'availableProducts' => $availableGlobalProducts,
            'localProducts' => $localCatalogProducts,
        ]);
    }

    public function import(Request $request)
    {
        $request->validate(['product_ids' => 'required|array']);
        
        $user = Auth::user();
        $globalProducts = GlobalProduct::find($request->input('product_ids'));

        foreach ($globalProducts as $globalProduct) {
            Product::create([
                'global_product_id' => $globalProduct->id,
                'name' => $globalProduct->name,
                'description' => $globalProduct->description,
                'sku' => $globalProduct->sku,
                'selling_price' => $globalProduct->selling_price,
                'category_id' => $globalProduct->category_id,
                'brand_id' => $globalProduct->brand_id,
                'measure_unit' => $globalProduct->measure_unit,
                'branch_id' => $user->branch_id,
                // Puedes poner valores por defecto para el stock
                'current_stock' => 0,
            ]);
        }
        return redirect()->back()->with('success', 'Productos importados a tu tienda.');
    }

    public function unlink(Request $request)
    {
        $request->validate(['product_ids' => 'required|array']);
        // Simplemente eliminamos el producto local. El producto global permanece intacto.
        Product::whereIn('id', $request->input('product_ids'))->delete();
        return redirect()->back()->with('success', 'Productos desvinculados de tu tienda.');
    }
}