<?php

use App\Http\Controllers\ProductStockController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::post('products/{product}/stock', [ProductStockController::class, 'store'])->name('products.stock.store');
    Route::post('products/stock/batch', [ProductStockController::class, 'batchStore'])->name('products.stock.batchStore');
});