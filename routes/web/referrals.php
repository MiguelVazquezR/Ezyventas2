<?php

use App\Http\Controllers\Admin\AdminReferralController;
use App\Http\Controllers\Subscription\ReferralController;
use Illuminate\Support\Facades\Route;

// -------------------------------------------------------
// Rutas de referidos para el suscriptor (dueño)
// -------------------------------------------------------
Route::middleware(['auth', 'verified'])->prefix('referrals')->name('referrals.')->group(function () {
    Route::get('/', [ReferralController::class, 'index'])->name('index');
    Route::get('/code', [ReferralController::class, 'getCode'])->name('code');
    Route::post('/bank-account', [ReferralController::class, 'saveBankAccount'])->name('bank-account');
});

// -------------------------------------------------------
// Rutas admin de referidos (solo superadmin: subscription_id = 1)
// -------------------------------------------------------
Route::middleware(['auth', 'verified'])->prefix('admin/referrals')->name('admin.referrals.')->group(function () {
    Route::get('/', [AdminReferralController::class, 'index'])->name('index');
    Route::post('/{referralUsage}/pay', [AdminReferralController::class, 'markPaid'])->name('pay');
    Route::get('/settings', [AdminReferralController::class, 'settings'])->name('settings');
    Route::put('/settings', [AdminReferralController::class, 'updateSettings'])->name('settings.update');
});
