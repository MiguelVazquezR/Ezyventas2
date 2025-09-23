<?php

use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('settings')->as('settings.')->group(function () {
    Route::get('/', [SettingsController::class, 'index'])->name('index');

    // Ruta para CREAR una nueva definición (desde el modal)
    Route::post('/', [SettingsController::class, 'store'])->name('store');

    // CORRECCIÓN: Se crea una ruta POST única para ACTUALIZAR los valores, evitando el conflicto.
    // El formulario principal de "Guardar Cambios" ahora apuntará aquí.
    Route::post('/values', [SettingsController::class, 'update'])->name('update');

    // Ruta para ACTUALIZAR una definición existente (desde el modal)
    Route::put('/{setting}', [SettingsController::class, 'updateDefinition'])->name('updateDefinition');
});

