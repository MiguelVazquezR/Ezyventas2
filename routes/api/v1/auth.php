<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Middleware\EnsureApiSubscriptionIsActive;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API v1 — Authentication
|--------------------------------------------------------------------------
|
| Token based authentication for the Flutter app (Sanctum personal access
| tokens). Every other /api/v1 module requires the token issued here.
|
| Mirrors the naming convention used by routes/web/*.php (kebab-case URL
| segments, module.action route names).
|
*/

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:api-login')
    ->name('auth.login');

Route::middleware(['auth:sanctum', EnsureApiSubscriptionIsActive::class])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
});
