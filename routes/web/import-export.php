<?php

use App\Http\Controllers\ImportExportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->as('import-export.')->group(function () {
    // Rutas de Productos
    Route::get('export/products', [ImportExportController::class, 'exportProducts'])->name('products.export');
    Route::post('import/products', [ImportExportController::class, 'importProducts'])->name('products.import');
    
    // Rutas de Clientes
    Route::get('export/customers', [ImportExportController::class, 'exportCustomers'])->name('customers.export');
    Route::post('import/customers', [ImportExportController::class, 'importCustomers'])->name('customers.import');
    
    // Rutas de Clientes
    Route::get('export/expenses', [ImportExportController::class, 'exportExpenses'])->name('expenses.export');
    Route::post('import/expenses', [ImportExportController::class, 'importExpenses'])->name('expenses.import');
});