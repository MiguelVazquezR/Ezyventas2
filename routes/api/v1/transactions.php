<?php

use App\Http\Controllers\Api\V1\Transactions\TransactionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API v1 — Sales history
|--------------------------------------------------------------------------
|
| Read only: the sales list and the sale detail. Cancellations, refunds and
| payments arrive in phase 3.
|
*/

Route::get('/transactions', [TransactionController::class, 'index'])
    ->name('transactions.index');

Route::get('/transactions/{transactionId}', [TransactionController::class, 'show'])
    ->whereNumber('transactionId')
    ->name('transactions.show');

Route::post('/transactions/{transactionId}/payments', [TransactionController::class, 'addPayment'])
    ->whereNumber('transactionId')
    ->name('transactions.payments.store');

Route::post('/transactions/{transactionId}/cancel', [TransactionController::class, 'cancel'])
    ->whereNumber('transactionId')
    ->name('transactions.cancel');

Route::post('/transactions/{transactionId}/refund', [TransactionController::class, 'refund'])
    ->whereNumber('transactionId')
    ->name('transactions.refund');

Route::put('/transactions/{transactionId}/payments/{paymentId}', [TransactionController::class, 'updatePayment'])
    ->whereNumber('transactionId')
    ->whereNumber('paymentId')
    ->name('transactions.payments.update');

Route::delete('/transactions/{transactionId}/payments/{paymentId}', [TransactionController::class, 'destroyPayment'])
    ->whereNumber('transactionId')
    ->whereNumber('paymentId')
    ->name('transactions.payments.destroy');
