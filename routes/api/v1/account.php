<?php

use App\Http\Controllers\Api\V1\Account\BranchSwitchController;
use App\Http\Controllers\Api\V1\Account\NotificationController;
use App\Http\Controllers\Api\V1\Account\ProfileController;
use App\Http\Controllers\Api\V1\Account\SupportController;
use App\Http\Controllers\Api\V1\Account\SubscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API v1 — Account (branch, notifications, support and profile)
|--------------------------------------------------------------------------
|
| Everything the account menu of the app needs: the branch selector, the
| notification counters, the support content and the user profile.
|
*/

Route::get('/notifications', [NotificationController::class, 'index'])
    ->name('notifications.index');

Route::get('/support', [SupportController::class, 'index'])
    ->name('support.index');

Route::put('/branch/switch/{branchId}', [BranchSwitchController::class, 'update'])
    ->whereNumber('branchId')
    ->name('branch.switch');

Route::get('/profile', [ProfileController::class, 'show'])
    ->name('profile.show');

Route::put('/profile', [ProfileController::class, 'update'])
    ->name('profile.update');

Route::delete('/profile/photo', [ProfileController::class, 'destroyPhoto'])
    ->name('profile.photo.destroy');

Route::put('/profile/password', [ProfileController::class, 'updatePassword'])
    ->name('profile.password.update');

Route::post('/profile/logout-other-devices', [ProfileController::class, 'logoutOtherDevices'])
    ->name('profile.logout-other-devices');

Route::get('/subscription', [SubscriptionController::class, 'show'])
    ->name('subscription.show');

Route::put('/subscription', [SubscriptionController::class, 'update'])
    ->name('subscription.update');

Route::post('/subscription/documents', [SubscriptionController::class, 'storeDocument'])
    ->name('subscription.documents.store');

Route::post('/subscription/payments/{paymentId}/request-invoice', [SubscriptionController::class, 'requestInvoice'])
    ->whereNumber('paymentId')
    ->name('subscription.payments.request-invoice');
