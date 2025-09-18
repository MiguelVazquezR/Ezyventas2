<?php

namespace App\Http\Controllers;

use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

class PromotionController extends Controller
{
    /**
     * Update the specified resource in storage.
     * Toggles the 'is_active' status of a promotion.
     */
    public function update(Request $request, Promotion $promotion)
    {
        // Opcional: Verificar que el usuario tiene permiso para modificar esta promoción
        // $this->authorize('update', $promotion);

        $promotion->update([
            'is_active' => !$promotion->is_active,
        ]);

        return Redirect::back()->with('success', 'Estado de la promoción actualizado.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Promotion $promotion)
    {
        // Opcional: Verificar que el usuario tiene permiso para eliminar esta promoción
        // $this->authorize('delete', $promotion);
        
        $promotion->delete();

        return Redirect::back()->with('success', 'Promoción eliminada con éxito.');
    }
}