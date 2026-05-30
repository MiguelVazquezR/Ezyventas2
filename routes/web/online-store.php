<?php

use App\Http\Controllers\OnlineStore\StoreConfigController;
use App\Http\Controllers\OnlineStore\StoreProductController;
use App\Http\Controllers\OnlineStore\OrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Online Store Admin Routes (Panel del Suscriptor)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'verified'])->prefix('online-store')->name('online-store.')->group(function () {

    // Store Configuration
    Route::get('/config', [StoreConfigController::class, 'show'])->name('config');
    Route::put('/config', [StoreConfigController::class, 'update'])->name('config.update');

    // Store Products (managing which products appear online)
    Route::get('/products', [StoreProductController::class, 'index'])->name('products.index');
    Route::get('/products/{product}/edit', [StoreProductController::class, 'edit'])->name('products.edit');
    Route::put('/products/{product}', [StoreProductController::class, 'update'])->name('products.update');
    Route::put('/products/{product}/toggle', [StoreProductController::class, 'toggle'])->name('products.toggle');
    Route::put('/products/{product}/toggle-featured', [StoreProductController::class, 'toggleFeatured'])->name('products.toggle-featured');
    Route::put('/products/sort-order', [StoreProductController::class, 'updateSortOrder'])->name('products.sort-order');

    // Orders
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::put('/orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.update-status');
});
