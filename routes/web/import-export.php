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
   
    // Rutas de servicios
    Route::get('export/services', [ImportExportController::class, 'exportServices'])->name('services.export');
    Route::post('import/services', [ImportExportController::class, 'importServices'])->name('services.import');
   
    // Rutas de ordenes servicios
    Route::get('export/service-orders', [ImportExportController::class, 'exportServiceOrders'])->name('service-orders.export');
    Route::post('import/service-orders', [ImportExportController::class, 'importServiceOrders'])->name('service-orders.import');
    
    // Rutas de cotizaciones
    Route::get('export/quotes', [ImportExportController::class, 'exportQuotes'])->name('quotes.export');
});