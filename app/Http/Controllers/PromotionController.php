<?php

namespace App\Http\Controllers;

use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

class PromotionController extends Controller
{
    public function update(Request $request, Promotion $promotion)
    {
        $newStatus = !$promotion->is_active;
        $statusText = $newStatus ? 'reactivada' : 'inactivada';
        $affectedProducts = $promotion->getAffectedProducts();

        $promotion->update(['is_active' => $newStatus]);

        foreach ($affectedProducts as $product) {
            activity()
                ->performedOn($product)
                ->causedBy(auth()->user())
                ->event('promo')
                ->withProperties(['promotion_name' => $promotion->name, 'status' => $statusText])
                ->log("La promoción '{$promotion->name}' ha sido {$statusText}.");
        }

        return Redirect::back()->with('success', 'Estado de la promoción actualizado.');
    }

    public function destroy(Promotion $promotion)
    {
        $promotionName = $promotion->name;
        $affectedProducts = $promotion->getAffectedProducts();
        
        $promotion->delete();

        foreach ($affectedProducts as $product) {
            activity()
                ->performedOn($product)
                ->causedBy(auth()->user())
                ->event('promo')
                ->withProperties(['promotion_name' => $promotionName])
                ->log("Se eliminó la promoción '{$promotionName}'.");
        }

        return Redirect::back()->with('success', 'Promoción eliminada con éxito.');
    }
}