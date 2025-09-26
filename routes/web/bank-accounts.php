<?php

use App\Http\Controllers\BankAccountController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::resource('bank-accounts', BankAccountController::class)->only([
        'store', 'update', 'destroy'
    ]);
});