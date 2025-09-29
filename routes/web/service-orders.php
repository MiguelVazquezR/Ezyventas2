<?php

use App\Http\Controllers\ServiceOrderController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::post('service-orders/batch-destroy', [ServiceOrderController::class, 'batchDestroy'])->name('service-orders.batchDestroy');
    Route::patch('service-orders/{serviceOrder}/status', [ServiceOrderController::class, 'updateStatus'])->name('service-orders.updateStatus'); // <-- Nueva ruta
    Route::resource('service-orders', ServiceOrderController::class);
    Route::post('service-orders/{serviceOrder}/payments', [ServiceOrderController::class, 'storePayment'])->name('service-orders.storePayment');
});
