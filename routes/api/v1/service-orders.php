<?php

use App\Http\Controllers\Api\V1\ServiceOrders\ServiceOrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API v1 — Service orders
|--------------------------------------------------------------------------
|
| Phase 2: the work list, the order detail, the status change and the diagnosis
| with evidence photos. Creating, editing and collecting orders arrive in phase 3.
|
*/

Route::get('/service-orders', [ServiceOrderController::class, 'index'])
    ->name('service-orders.index');

Route::get('/service-orders/{serviceOrderId}', [ServiceOrderController::class, 'show'])
    ->whereNumber('serviceOrderId')
    ->name('service-orders.show');

Route::post('/service-orders', [ServiceOrderController::class, 'store'])
    ->name('service-orders.store');

Route::put('/service-orders/{serviceOrderId}', [ServiceOrderController::class, 'update'])
    ->whereNumber('serviceOrderId')
    ->name('service-orders.update');

Route::post('/service-orders/{serviceOrderId}/ensure-transaction', [ServiceOrderController::class, 'ensureTransaction'])
    ->whereNumber('serviceOrderId')
    ->name('service-orders.ensure-transaction');

Route::post('/service-orders/{serviceOrderId}/payments', [ServiceOrderController::class, 'storePayment'])
    ->whereNumber('serviceOrderId')
    ->name('service-orders.payments.store');

Route::delete('/service-orders/{serviceOrderId}', [ServiceOrderController::class, 'destroy'])
    ->whereNumber('serviceOrderId')
    ->name('service-orders.destroy');

Route::patch('/service-orders/{serviceOrderId}/status', [ServiceOrderController::class, 'updateStatus'])
    ->whereNumber('serviceOrderId')
    ->name('service-orders.status');

Route::post('/service-orders/{serviceOrderId}/diagnosis', [ServiceOrderController::class, 'storeDiagnosis'])
    ->whereNumber('serviceOrderId')
    ->name('service-orders.diagnosis');
