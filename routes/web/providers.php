<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProviderController;

/*
|--------------------------------------------------------------------------
| Rutas de Proveedores (Gestión)
|--------------------------------------------------------------------------
|
| Estas rutas son para el modal de gestión (CRUD) de proveedores.
| Deben estar protegidas por el middleware 'auth' en tu archivo web.php.
|
*/

// Definimos las rutas 'resource' solo para las acciones que necesitamos
// index -> GET /providers (para listar)
// update -> PUT/PATCH /providers/{provider} (para actualizar)
// destroy -> DELETE /providers/{provider} (para eliminar)
Route::middleware('auth')->group(function () {
    Route::resource('providers', ProviderController::class)
        ->only(['index', 'update', 'destroy']);
});