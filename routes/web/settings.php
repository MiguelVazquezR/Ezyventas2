<?php

use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('settings')->as('settings.')->group(function () {
    // Ruta principal para ver la vista de configuraciones
    Route::get('/', [SettingsController::class, 'index'])->name('index');

    // Ruta para ACTUALIZAR los valores ingresados en los inputs
    Route::post('/values', [SettingsController::class, 'update'])->name('update');

    // Ruta para CREAR una nueva definición (desde el modal de Superadmin)
    Route::post('/definition', [SettingsController::class, 'storeDefinition'])->name('store-definition');

    // Ruta para ACTUALIZAR una definición existente (desde el modal de Superadmin)
    Route::put('/definition/{setting}', [SettingsController::class, 'updateDefinition'])->name('update-definition');

    // Ruta para ELIMINAR una definición existente (solo Superadmin ID 1)
    Route::delete('/definition/{setting}', [SettingsController::class, 'destroyDefinition'])->name('destroy-definition');
});