<?php

use App\Http\Controllers\ProductImportExportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('products')->as('products.')->group(function () {
    Route::get('additionals/export', [ProductImportExportController::class, 'export'])->name('export');
    Route::post('import', [ProductImportExportController::class, 'import'])->name('import');
});