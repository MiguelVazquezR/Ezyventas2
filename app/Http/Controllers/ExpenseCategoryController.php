<?php

namespace App\Http\Controllers;

use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ExpenseCategoryController extends Controller
{
    public function index()
    {
        $subscriptionId = Auth::user()->branch->subscription_id;
        $categories = ExpenseCategory::where('subscription_id', $subscriptionId)
            ->latest()
            ->get();

        return response()->json($categories);
    }

    public function update(Request $request, ExpenseCategory $expenseCategory)
    {
        if ($expenseCategory->subscription_id !== Auth::user()->branch->subscription_id) {
            abort(403, 'No autorizado');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $expenseCategory->update($validated);

        return response()->json($expenseCategory);
    }

    /**
     * Elimina la categoría. Si tiene gastos, requiere un ID de categoría destino para migrarlos.
     */
    public function destroy(Request $request, ExpenseCategory $expenseCategory)
    {
        if ($expenseCategory->subscription_id !== Auth::user()->branch->subscription_id) {
            abort(403, 'No autorizado');
        }

        // 1. Verificar si tiene gastos asociados
        $expensesCount = $expenseCategory->expenses()->count();

        if ($expensesCount > 0) {
            // 2. Verificar si el usuario envió la categoría destino
            $migrateToId = $request->input('migrate_to_id');

            if ($migrateToId) {
                // Validar que la categoría destino exista y sea de la misma suscripción
                $targetCategory = ExpenseCategory::where('id', $migrateToId)
                    ->where('subscription_id', $expenseCategory->subscription_id)
                    ->where('id', '!=', $expenseCategory->id) // Evitar migrar a sí misma
                    ->first();

                if (!$targetCategory) {
                    return response()->json(['message' => 'La categoría de destino no es válida.'], 422);
                }

                // 3. Mover los gastos a la nueva categoría
                // Usamos getForeignKeyName() para obtener dinámicamente el nombre de la columna (ej. expense_category_id)
                $foreignKey = $expenseCategory->expenses()->getForeignKeyName();
                $expenseCategory->expenses()->update([$foreignKey => $targetCategory->id]);

            } else {
                // 4. Si hay gastos pero no hay destino, devolver código especial para el Frontend
                return response()->json([
                    'message' => 'Esta categoría tiene gastos asociados.',
                    'code' => 'expenses_exist', // Código clave para el modal Vue
                    'expenses_count' => $expensesCount
                ], 422);
            }
        }

        // 5. Eliminar la categoría (ahora vacía)
        $expenseCategory->delete();

        return response()->json(['status' => 'success', 'message' => 'Categoría eliminada con éxito.']);
    }
}