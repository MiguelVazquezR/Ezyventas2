<?php

use App\Http\Controllers\SessionCashMovementController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::put('session-cash-movements/{movement}', [SessionCashMovementController::class, 'update'])->name('session-cash-movements.update');
    Route::delete('session-cash-movements/{movement}', [SessionCashMovementController::class, 'destroy'])->name('session-cash-movements.destroy');
    Route::post('cash-register-sessions/{session}/movements', [SessionCashMovementController::class, 'store'])
        ->name('session-cash-movements.store');
});
