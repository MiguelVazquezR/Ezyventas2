<?php

use App\Http\Controllers\BankAccountController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::resource('bank-accounts', BankAccountController::class)->only([
        'store',
        'update',
        'destroy'
    ]);

    Route::get('/branch-bank-accounts', [BankAccountController::class, 'getForBranch'])
        ->name('branch-bank-accounts');
});
