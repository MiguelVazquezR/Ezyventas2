<?php

use App\Http\Controllers\Invoices\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('invoices')->name('invoices.')->group(function () {
    Route::get('/', [InvoiceController::class, 'index'])->name('index');
    Route::get('/create', [InvoiceController::class, 'create'])->name('create');
    Route::post('/', [InvoiceController::class, 'store'])->name('store');
    Route::get('/settings', [InvoiceController::class, 'settings'])->name('settings');
    Route::put('/settings', [InvoiceController::class, 'updateSettings'])->name('updateSettings');
    Route::get('/{invoice}', [InvoiceController::class, 'show'])->name('show');
    Route::post('/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('cancel');
});
