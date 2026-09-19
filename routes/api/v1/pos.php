<?php

use App\Http\Controllers\Api\V1\Pos\PointOfSaleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API v1 — POS (sale registration)
|--------------------------------------------------------------------------
|
| The three ways of booking money from the phone: paid sale, layaway and order.
| All of them require an open cash register session of the branch.
|
*/

Route::post('/pos/checkout', [PointOfSaleController::class, 'checkout'])
    ->name('pos.checkout');

Route::post('/pos/layaway', [PointOfSaleController::class, 'layaway'])
    ->name('pos.layaway');

Route::post('/pos/store-order', [PointOfSaleController::class, 'storeOrder'])
    ->name('pos.store-order');
