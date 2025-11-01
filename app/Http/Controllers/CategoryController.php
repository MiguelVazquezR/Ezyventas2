<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['product', 'service'])],
        ]);

        $subscriptionId = Auth::user()->branch->subscription_id;

        $categories = Category::where('subscription_id', $subscriptionId)
            ->where('type', $validated['type'])
            ->get();

        return response()->json($categories);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Category $category)
    {
        // Verificar que la categoría pertenece a la suscripción del usuario
        if ($category->subscription_id !== Auth::user()->branch->subscription_id) {
            abort(403, 'Acción no autorizada.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in(['product', 'service'])],
        ]);

        $category->update($validated);

        return response()->json($category);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Category $category)
    {
        // Verificar que la categoría pertenece a la suscripción del usuario
        if ($category->subscription_id !== Auth::user()->branch->subscription_id) {
            abort(403, 'Acción no autorizada.');
        }

        // Opcional: Verificar si la categoría está en uso antes de eliminar
        if ($category->products()->exists() || $category->services()->exists()) {
            return response()->json(['message' => 'No se puede eliminar la categoría porque está en uso.'], 422);
        }

        $category->delete();

        return response()->json(null, 204);
    }
}