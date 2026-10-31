<?php

use App\Http\Controllers\Api\V1\Customers\CustomerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API v1 — Customers
|--------------------------------------------------------------------------
*/

Route::get('/customers', [CustomerController::class, 'index'])
    ->name('customers.index');

Route::post('/customers', [CustomerController::class, 'store'])
    ->name('customers.store');

Route::get('/customers/{customerId}', [CustomerController::class, 'show'])
    ->whereNumber('customerId')
    ->name('customers.show');
