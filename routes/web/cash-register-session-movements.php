<?php

use App\Http\Controllers\SessionCashMovementController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::post('cash-register-sessions/{session}/movements', [SessionCashMovementController::class, 'store'])
        ->name('session-cash-movements.store');
});