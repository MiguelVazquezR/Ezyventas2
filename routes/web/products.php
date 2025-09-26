<?php

use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del Módulo de Productos
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::post('products/batch-destroy', [ProductController::class, 'batchDestroy'])->name('products.batchDestroy');
    Route::resource('products', ProductController::class);
});