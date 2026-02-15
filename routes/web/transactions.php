<?php

use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // Rutas para acciones especÃ­ficas
    Route::post('transactions/{transaction}/cancel', [TransactionController::class, 'cancel'])
        ->name('transactions.cancel');

    Route::post('transactions/{transaction}/refund', [TransactionController::class, 'refund'])
        ->name('transactions.refund');

    Route::post('/transactions/{transaction}/payment', [TransactionController::class, 'addPayment'])
        ->name('transactions.addPayment');

    Route::put('/transactions/{transaction}/payments/{payment}', [TransactionController::class, 'updatePayment'])
        ->name('transactions.updatePayment');

    // Intercambio normal (Ventas completadas)
    Route::post('/transactions/{transaction}/exchange', [TransactionController::class, 'exchange'])
        ->name('transactions.exchange');

    Route::put('/transactions/{transaction}/reschedule-order', [TransactionController::class, 'rescheduleOrder'])
        ->name('transactions.reschedule-order');

    Route::put('/transactions/{transaction}/extend-layaway', [TransactionController::class, 'extendLayaway'])
        ->name('transactions.extend-layaway');

    // Intercambio exclusivo para Apartados
    Route::post('/transactions/{transaction}/exchange-layaway', [TransactionController::class, 'exchangeLayaway'])
        ->name('transactions.exchange-layaway');

    Route::put('/transactions/{transaction}/update-date', [TransactionController::class, 'updateDate'])
        ->name('transactions.update-date');

    Route::post('/pos/store-order', [TransactionController::class, 'storeOrder'])
        ->name('pos.store-order');

    Route::get('/transactions/search-products', [TransactionController::class, 'searchProducts'])
        ->name('transactions.search-products');

    // para obtener deudas pendientes de un cliente (usada en el modal de intercambio)
    Route::get('/customers/{customer}/pending-debts', [TransactionController::class, 'pendingDebts'])
        ->name('customers.pending-debts');

    // Rutas del resource
    Route::resource('transactions', TransactionController::class)->except(['create', 'store', 'edit', 'update']);
});
