<?php

use App\Http\Controllers\AttributeDefinitionController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del Módulo de Productos
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    Route::post('products/batch-destroy', [ProductController::class, 'batchDestroy'])->name('products.batchDestroy');
    Route::post('products/bulk-update', [ProductController::class, 'bulkUpdate'])->name('products.bulkUpdate');
    Route::post('products/update-price-pos', [ProductController::class, 'updatePriceFromPOS'])->name('products.update-price-pos');
    Route::put('products/{product}/toggle-online', [ProductController::class, 'toggleOnline'])->name('products.toggle-online');
    Route::put('products/{product}/toggle-featured', [ProductController::class, 'toggleFeatured'])->name('products.toggle-featured');
    Route::put('products/{product}/toggle-pos', [ProductController::class, 'togglePos'])->name('products.toggle-pos');
    Route::resource('products', ProductController::class);
    Route::resource('attribute-definitions', AttributeDefinitionController::class)->except([
        'create',
        'edit'
    ]);
});
