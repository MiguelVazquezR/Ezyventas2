<?php

use App\Http\Controllers\ProductPromotionController;
use Illuminate\Support\Facades\Route;

Route::get('products/{product}/promotions/create', [ProductPromotionController::class, 'create'])->name('products.promotions.create');
Route::post('products/{product}/promotions', [ProductPromotionController::class, 'store'])->name('products.promotions.store');
