<?php

use App\Http\Middleware\EnsureApiSubscriptionIsActive;
use App\Http\Controllers\WebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/**
 * Webhook de Mercado Pago para notificaciones de pago.
 * Endpoint público sin autenticación — MP envía notificaciones automáticamente.
 */
Route::post('/webhooks/mercadopago', [WebhookController::class, 'mercadopago'])
    ->name('webhooks.mercadopago');

/*
|--------------------------------------------------------------------------
| Mobile API (versioned)
|--------------------------------------------------------------------------
|
| Consumed by the Flutter app. One file per module inside routes/api/v1/,
| mirroring the routes/web/*.php layout of the web application.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    // Public: token issue.
    require __DIR__ . '/api/v1/auth.php';

    // Every other module needs a valid token and an active subscription.
    Route::middleware(['auth:sanctum', EnsureApiSubscriptionIsActive::class])->group(function () {
        require __DIR__ . '/api/v1/catalog.php';
        require __DIR__ . '/api/v1/customers.php';
        require __DIR__ . '/api/v1/cash-registers.php';
        require __DIR__ . '/api/v1/bank-accounts.php';
        require __DIR__ . '/api/v1/transactions.php';
        require __DIR__ . '/api/v1/service-orders.php';
        require __DIR__ . '/api/v1/pos.php';
        require __DIR__ . '/api/v1/printing.php';
        require __DIR__ . '/api/v1/account.php';
    });
});


