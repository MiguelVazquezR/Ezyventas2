<?php

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerPaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::post('customers/{customer}/payments', [CustomerPaymentController::class, 'store'])->name('customers.payments.store');
    Route::post('customers/batch-destroy', [CustomerController::class, 'batchDestroy'])->name('customers.batchDestroy');
    Route::resource('customers', CustomerController::class);
});