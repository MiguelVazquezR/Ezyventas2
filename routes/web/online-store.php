<?php

use App\Http\Controllers\OnlineStore\StoreConfigController;
use App\Http\Controllers\OnlineStore\OrderController;
use App\Http\Controllers\OnlineStore\MercadoPagoController;
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

    // Mercado Pago OAuth
    Route::get('/mp/connect', [MercadoPagoController::class, 'connect'])->name('mp.connect');
    Route::get('/mp/callback', [MercadoPagoController::class, 'callback'])->name('mp.callback');
    Route::post('/mp/disconnect', [MercadoPagoController::class, 'disconnect'])->name('mp.disconnect');

    // Orders
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::put('/orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.update-status');
    Route::delete('/orders/{order}', [OrderController::class, 'destroy'])->name('orders.destroy');
});
