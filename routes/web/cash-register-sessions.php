<?php

use App\Http\Controllers\CashRegisterSessionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('cash-register-sessions/{cashRegisterSession}/print', [CashRegisterSessionController::class, 'print'])->name('cash-register-sessions.print');
    Route::resource('cash-register-sessions', CashRegisterSessionController::class);
});