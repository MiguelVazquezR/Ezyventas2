<?php

use App\Http\Controllers\CashRegisterSessionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::post('cash-register-sessions', [CashRegisterSessionController::class, 'store'])->name('cash-register-sessions.store');
    Route::put('cash-register-sessions/{cashRegisterSession}', [CashRegisterSessionController::class, 'update'])->name('cash-register-sessions.update');
});