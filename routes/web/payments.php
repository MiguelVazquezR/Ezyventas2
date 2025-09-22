<?php

use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // Ruta para registrar un nuevo pago (abono) a una transacción existente
    Route::post('transactions/{transaction}/payments', [PaymentController::class, 'store'])->name('transactions.payments.store');
});