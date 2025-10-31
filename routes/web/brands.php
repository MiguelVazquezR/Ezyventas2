<?php

use App\Http\Controllers\BrandController;
use Illuminate\Support\Facades\Route;

// Agrupa las rutas de gestión de marcas bajo la protección de 'auth'
Route::middleware('auth')->group(function () {
    
    // Define las rutas resource para 'brands', pero solo para las acciones que necesitamos:
    // GET /brands -> BrandController@index (Obtener lista)
    // PUT /brands/{brand} -> BrandController@update (Actualizar)
    // DELETE /brands/{brand} -> BrandController@destroy (Eliminar)
    Route::resource('brands', BrandController::class)
        ->only(['index', 'update', 'destroy']);
        
});