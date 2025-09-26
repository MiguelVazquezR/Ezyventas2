<?php

use App\Http\Controllers\CustomerController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::post('customers/batch-destroy', [CustomerController::class, 'batchDestroy'])->name('customers.batchDestroy');
    Route::resource('customers', CustomerController::class);
});