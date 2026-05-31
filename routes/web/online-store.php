<?php

use App\Http\Controllers\OnlineStore\StoreConfigController;
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
    Route::post('/config/check-slug', [StoreConfigController::class, 'checkSlug'])->name('config.check-slug');

    // Orders
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::put('/orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.update-status');
});
