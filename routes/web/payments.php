<?php

use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // Route::post('transactions/{transaction}/payments', [PaymentController::class, 'store'])->name('transactions.payments.store');
});