<?php

use App\Http\Controllers\Api\V1\BankAccounts\BankAccountController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API v1 — Bank accounts
|--------------------------------------------------------------------------
*/

Route::get('/bank-accounts', [BankAccountController::class, 'index'])
    ->name('bank-accounts.index');
