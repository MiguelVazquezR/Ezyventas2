<?php

use App\Http\Controllers\FinancialReportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    // ... tus otras rutas de reportes
    Route::get('/financial-control/export', [FinancialReportController::class, 'export'])->name('financial-control.export');
});
