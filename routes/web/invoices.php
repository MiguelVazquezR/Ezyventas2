<?php

use App\Http\Controllers\Invoices\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('invoices')->name('invoices.')->group(function () {
    Route::get('/', [InvoiceController::class, 'index'])->name('index');
    Route::get('/create', [InvoiceController::class, 'create'])->name('create');
    Route::post('/', [InvoiceController::class, 'store'])->name('store');
    Route::get('/settings', [InvoiceController::class, 'settings'])->name('settings');
    Route::post('/fiscal-profiles', [InvoiceController::class, 'storeFiscalProfile'])->name('storeFiscalProfile');
    Route::post('/fiscal-profiles/upload-csd', [InvoiceController::class, 'uploadCsd'])->name('uploadCsd');
    Route::delete('/fiscal-profiles/{fiscalProfile}', [InvoiceController::class, 'destroy'])->name('destroyFiscalProfile');
    Route::get('/{invoice}', [InvoiceController::class, 'show'])->name('show');
    Route::post('/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('cancel');
});
