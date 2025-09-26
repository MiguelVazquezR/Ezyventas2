<?php

use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\SubscriptionUpgradeController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/subscription', [SubscriptionController::class, 'show'])->name('subscription.show');
    Route::put('/subscription', [SubscriptionController::class, 'update'])->name('subscription.update');
    Route::post('/subscription/documents', [SubscriptionController::class, 'storeDocument'])->name('subscription.documents.store');
    Route::post('/subscription/payments/{payment}/request-invoice', [SubscriptionController::class, 'requestInvoice'])->name('subscription.payments.request-invoice');

    // Rutas para la mejora de la suscripción
    Route::get('/subscription/upgrade', [SubscriptionUpgradeController::class, 'show'])->name('subscription.upgrade.show');
    Route::post('/subscription/upgrade', [SubscriptionUpgradeController::class, 'store'])->name('subscription.upgrade.store');
});