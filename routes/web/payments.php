<?php

use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // La ruta ahora es más genérica y apunta al nuevo controlador.
    Route::post('/transactions/{transaction}/payments', [PaymentController::class, 'store'])
        ->name('payments.store');
});
