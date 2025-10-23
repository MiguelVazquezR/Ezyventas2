<?php

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerPaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::post('customers/{customer}/payments', [CustomerPaymentController::class, 'store'])->name('customers.payments.store');
    Route::post('customers/batch-destroy', [CustomerController::class, 'batchDestroy'])->name('customers.batchDestroy');

    // Nueva ruta para el estado de cuenta
    Route::get('customers/{customer}/print-statement', [CustomerController::class, 'printStatement'])->name('customers.printStatement');

    Route::resource('customers', CustomerController::class);
});