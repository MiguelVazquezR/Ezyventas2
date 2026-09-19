<?php

use App\Http\Controllers\Api\V1\CashRegisters\CashRegisterSessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API v1 — Cash register sessions
|--------------------------------------------------------------------------
|
| Phase 1 reads the state of the shift. Phase 3 adds opening a new shift and
| joining the shift opened by a teammate. Closing the register (the cut) is
| still done from the web app.
|
*/

Route::get('/cash-register-sessions/current', [CashRegisterSessionController::class, 'current'])
    ->name('cash-register-sessions.current');

Route::post('/cash-register-sessions', [CashRegisterSessionController::class, 'store'])
    ->name('cash-register-sessions.store');

Route::post('/cash-register-sessions/{cashRegisterSessionId}/join', [CashRegisterSessionController::class, 'join'])
    ->whereNumber('cashRegisterSessionId')
    ->name('cash-register-sessions.join');

Route::post('/cash-register-sessions/{cashRegisterSessionId}/leave', [CashRegisterSessionController::class, 'leave'])
    ->whereNumber('cashRegisterSessionId')
    ->name('cash-register-sessions.leave');

Route::post('/cash-register-sessions/rejoin-or-start', [CashRegisterSessionController::class, 'rejoinOrStart'])
    ->name('cash-register-sessions.rejoin-or-start');

Route::get('/cash-register-sessions/{cashRegisterSessionId}/summary', [CashRegisterSessionController::class, 'summary'])
    ->whereNumber('cashRegisterSessionId')
    ->name('cash-register-sessions.summary');

Route::put('/cash-register-sessions/{cashRegisterSessionId}', [CashRegisterSessionController::class, 'close'])
    ->whereNumber('cashRegisterSessionId')
    ->name('cash-register-sessions.close');
