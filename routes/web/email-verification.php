<?php

use App\Http\Controllers\Auth\EmailVerificationCodeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| E-mail verification (OTP code)
|--------------------------------------------------------------------------
|
| These routes override Fortify's default "click the link" verification with
| an OTP-code flow. They keep the `verification.*` naming convention so the
| `verified` middleware and the CheckOnboardingStatus middleware keep
| redirecting to this screen as before.
|
| Files are registered after Fortify's own routes (web.php loads later), so
| the matching URIs below take precedence over the package defaults.
|
*/

Route::middleware(['auth', 'throttle:6,1'])->group(function () {
    Route::get('/email/verify', [EmailVerificationCodeController::class, 'show'])
        ->name('verification.notice');

    Route::post('/email/verify/code', [EmailVerificationCodeController::class, 'verify'])
        ->name('verification.code');

    Route::post('/email/verify/change-email', [EmailVerificationCodeController::class, 'changeEmail'])
        ->name('verification.change-email');

    Route::post('/email/verification-notification', [EmailVerificationCodeController::class, 'resend'])
        ->name('verification.send');
});
