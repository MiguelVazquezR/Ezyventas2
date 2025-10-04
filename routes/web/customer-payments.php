<?php

use App\Http\Controllers\CustomerPaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::post('/customers/{customer}/add-balance', [CustomerPaymentController::class, 'store'])
        ->name('customers.addBalance');
});