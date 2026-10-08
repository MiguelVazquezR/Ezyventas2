<?php

use App\Http\Controllers\Api\V1\Catalog\CategoryController;
use App\Http\Controllers\Api\V1\Catalog\ProductController;
use App\Http\Controllers\Api\V1\Catalog\ServiceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API v1 — Catalog
|--------------------------------------------------------------------------
|
| Products of the POS (branch aware prices, promotions, variants and stock)
| plus the categories and services used by the service orders module.
|
*/

Route::get('/catalog/products', [ProductController::class, 'index'])
    ->name('catalog.products.index');

Route::get('/catalog/products/{productId}', [ProductController::class, 'show'])
    ->whereNumber('productId')
    ->name('catalog.products.show');

Route::get('/catalog/categories', [CategoryController::class, 'index'])
    ->name('catalog.categories.index');

Route::get('/catalog/services', [ServiceController::class, 'index'])
    ->name('catalog.services.index');
