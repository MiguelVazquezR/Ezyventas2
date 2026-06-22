<?php

use App\Http\Controllers\InventoryReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas de Reportes de Inventario
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    Route::get('/productos/reportes', [InventoryReportController::class, 'index'])->name('products.reports');
    Route::get('/productos/reportes/imprimir', [InventoryReportController::class, 'print'])->name('products.reports.print');
    Route::get('/productos/reportes/generar', [InventoryReportController::class, 'generate'])->name('products.reports.generate');
});
