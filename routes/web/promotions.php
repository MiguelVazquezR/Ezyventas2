<?php

use App\Http\Controllers\ProductPromotionController;
use App\Http\Controllers\PromotionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('products/{product}/promotions/create', [ProductPromotionController::class, 'create'])->name('products.promotions.create');
    Route::post('products/{product}/promotions', [ProductPromotionController::class, 'store'])->name('products.promotions.store');
    // Rutas para la gestión general de promociones
    Route::patch('promotions/{promotion}', [PromotionController::class, 'update'])->name('promotions.update');
    Route::delete('promotions/{promotion}', [PromotionController::class, 'destroy'])->name('promotions.destroy');
});
