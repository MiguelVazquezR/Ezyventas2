<?php

use App\Http\Controllers\CashRegisterSessionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('cash-register-sessions/{cashRegisterSession}/print', [CashRegisterSessionController::class, 'print'])->name('cash-register-sessions.print');
    Route::post('cash-register-sessions/{session}/join', [CashRegisterSessionController::class, 'join'])->name('cash-register-sessions.join');
    Route::post('cash-register-sessions/{session}/leave', [CashRegisterSessionController::class, 'leave'])
        ->name('cash-register-sessions.leave');
    Route::resource('cash-register-sessions', CashRegisterSessionController::class);
});