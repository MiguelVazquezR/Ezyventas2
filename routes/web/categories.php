<?php

use App\Http\Controllers\CategoryController;
use Illuminate\Support\Facades\Route;

// Asegúrate de que este grupo tenga el middleware 'auth' y 'verified'
// que usas en el resto de tu aplicación.
Route::middleware(['auth'])->prefix('app')->group(function () {
    Route::resource('categories', CategoryController::class)->only([
        'index',   // GET /app/categories (para obtener la lista)
        'update',  // PUT/PATCH /app/categories/{category}
        'destroy'  // DELETE /app/categories/{category}
    ]);
});