<?php

use App\Http\Controllers\ServiceController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // Ruta para la eliminación masiva de servicios
    Route::post('services/batch-destroy', [ServiceController::class, 'batchDestroy'])->name('services.batchDestroy');
    
    // Rutas estándar para el CRUD de servicios (index, create, store, show, edit, update, destroy)
    Route::resource('services', ServiceController::class);
});