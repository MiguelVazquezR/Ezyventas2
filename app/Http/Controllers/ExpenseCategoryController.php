<?php

namespace App\Http\Controllers;

use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth; // <-- Importar Auth

class ExpenseCategoryController extends Controller
{
    /**
     * Muestra una lista de las categorías de gastos.
     * Esta función es llamada por el modal de gestión para obtener los datos.
     */
    public function index()
    {
        // 1. Obtener el ID de la suscripción del usuario autenticado.
        $subscriptionId = Auth::user()->branch->subscription_id;

        // 2. Buscar todas las categorías de gastos que pertenecen a esa suscripción.
        $categories = ExpenseCategory::where('subscription_id', $subscriptionId)
            ->latest() // Ordenar por más reciente
            ->get();

        // 3. Devolver las categorías como JSON.
        return response()->json($categories);
    }

    /**
     * Actualiza la categoría de gasto especificada en la base de datos.
     * Esta función es llamada por la edición en línea del modal.
     */
    public function update(Request $request, ExpenseCategory $expenseCategory)
    {
        // 1. Autorización: Asegurarse de que el usuario solo pueda editar sus propias categorías.
        if ($expenseCategory->subscription_id !== Auth::user()->branch->subscription_id) {
            abort(403, 'No autorizado');
        }

        // 2. Validación: Validar los datos de entrada.
        // 'name' es requerido, 'description' es opcional (nullable).
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        // 3. Actualizar la categoría con los datos validados.
        $expenseCategory->update($validated);

        // 4. Devolver la categoría actualizada como JSON.
        return response()->json($expenseCategory);
    }

    /**
     * Elimina la categoría de gasto especificada de la base de datos.
     * Esta función es llamada por el botón de eliminar en el modal.
     */
    public function destroy(ExpenseCategory $expenseCategory)
    {
        // 1. Autorización: Asegurarse de que el usuario solo pueda eliminar sus propias categorías.
        if ($expenseCategory->subscription_id !== Auth::user()->branch->subscription_id) {
            abort(403, 'No autorizado');
        }

        // 2. Validación de negocio: Comprobar si la categoría tiene gastos asociados.
        // Se usa la relación `expenses()` definida en el modelo ExpenseCategory.
        if ($expenseCategory->expenses()->exists()) {
            // 3. Si está en uso, devolver un error 422 (Unprocessable Entity) que el modal pueda leer.
            return response()->json([
                'message' => 'No se puede eliminar la categoría porque ya tiene gastos asociados.'
            ], 422);
        }

        // 4. Si no está en uso, eliminar la categoría.
        $expenseCategory->delete();

        // 5. Devolver una respuesta de éxito.
        return response()->json(['status' => 'success', 'message' => 'Categoría eliminada con éxito.']);
    }
}
