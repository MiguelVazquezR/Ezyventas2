<?php

use App\Http\Controllers\ExpenseImportExportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('expenses')->as('expenses.')->group(function () {
    Route::get('additionals/export', [ExpenseImportExportController::class, 'export'])->name('export');
    Route::post('import', [ExpenseImportExportController::class, 'import'])->name('import');
});