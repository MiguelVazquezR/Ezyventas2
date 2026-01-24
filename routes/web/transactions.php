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

    Route::put('/transactions/{transaction}/payments/{payment}', [TransactionController::class, 'updatePayment'])
        ->name('transactions.updatePayment');

    Route::post('/transactions/{transaction}/exchange', [TransactionController::class, 'exchange'])
        ->name('transactions.exchange');

    Route::get('/transactions/search-products', [TransactionController::class, 'searchProducts'])
        ->name('transactions.search-products');

    // Nueva ruta para obtener deudas pendientes de un cliente (usada en el modal de intercambio)
    Route::get('/customers/{customer}/pending-debts', [TransactionController::class, 'pendingDebts'])
        ->name('customers.pending-debts');

    // Rutas del resource
    Route::resource('transactions', TransactionController::class)->except(['create', 'store', 'edit', 'update', 'destroy']);
});
