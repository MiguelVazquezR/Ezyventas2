<?php

use App\Http\Controllers\TransactionPaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::post('/transactions/{transaction}/payments', [TransactionPaymentController::class, 'store'])->name('transactions.payments.store');
});