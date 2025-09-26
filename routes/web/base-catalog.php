<?php

use App\Http\Controllers\BaseCatalogController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('products/base-catalog')->as('products.base-catalog.')->group(function () {
    Route::get('/index', [BaseCatalogController::class, 'index'])->name('index');
    Route::post('/import', [BaseCatalogController::class, 'import'])->name('import');
    Route::post('/unlink', [BaseCatalogController::class, 'unlink'])->name('unlink');
});