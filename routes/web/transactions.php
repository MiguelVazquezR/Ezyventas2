<?php

use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // Rutas para acciones específicas
    Route::post('transactions/{transaction}/cancel', [TransactionController::class, 'cancel'])
        ->name('transactions.cancel');

    Route::post('transactions/{transaction}/refund', [TransactionController::class, 'refund'])
        ->name('transactions.refund');

    Route::post('/transactions/{transaction}/payment', [TransactionController::class, 'addPayment'])
        ->name('transactions.addPayment');

    Route::post('/transactions/{transaction}/exchange', [TransactionController::class, 'exchange'])
        ->name('transactions.exchange');

    // Rutas del resource
    Route::resource('transactions', TransactionController::class)->except(['create', 'store', 'edit', 'update', 'destroy']);
});
