<?php

use App\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // Es una ruta singular porque el usuario solo puede ver su propia suscripción.
    Route::get('/subscription', [SubscriptionController::class, 'show'])->name('subscription.show');
});