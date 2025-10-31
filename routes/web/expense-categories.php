<?php

use App\Http\Controllers\ExpenseCategoryController;
use Illuminate\Support\Facades\Route;

// --- NUEVO: Rutas para la gestión de Categorías de Gastos ---
Route::middleware(['auth', 'verified'])->prefix('expense-categories')->as('expense-categories.')->group(function () {

    // Obtener la lista de categorías de gastos
    Route::get('/', [ExpenseCategoryController::class, 'index'])->name('index');

    // Actualizar una categoría de gasto
    Route::put('/{expenseCategory}', [ExpenseCategoryController::class, 'update'])->name('update');

    // Eliminar una categoría de gasto
    Route::delete('/{expenseCategory}', [ExpenseCategoryController::class, 'destroy'])->name('destroy');
});
