<?php

use App\Http\Controllers\Billing\FiscalProfileController;
use App\Http\Controllers\Billing\InvoiceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Billing Routes
|--------------------------------------------------------------------------
|
| Modular billing module with three sections:
|   /billing/dashboard    — KPIs & stamp usage overview
|   /billing/invoices     — CFDI invoice CRUD
|   /billing/settings     — Fiscal profiles & CSD management
|
*/

Route::middleware(['auth', 'verified'])->prefix('billing')->name('billing.')->group(function () {

    // ── Dashboard ──────────────────────────────────────────
    Route::get('/dashboard', [InvoiceController::class, 'dashboard'])->name('dashboard');

    // ── Invoices (CFDI comprobantes) ───────────────────────
    Route::prefix('invoices')->name('invoices.')->group(function () {
        Route::get('/', [InvoiceController::class, 'index'])->name('index');
        Route::get('/create', [InvoiceController::class, 'create'])->name('create');
        Route::post('/', [InvoiceController::class, 'store'])->name('store');
        Route::get('/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('pdf');
        Route::get('/{invoice}/xml', [InvoiceController::class, 'downloadXml'])->name('xml');
        Route::get('/{invoice}', [InvoiceController::class, 'show'])->name('show');
        Route::post('/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('cancel');
        Route::post('/{invoice}/stamp', [InvoiceController::class, 'stamp'])->name('stamp');
        Route::delete('/{invoice}', [InvoiceController::class, 'destroy'])->name('destroy');
    });

    // ── Settings (Fiscal profiles & CSD) ───────────────────
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [InvoiceController::class, 'settings'])->name('index');
        Route::post('/toggle-facturacion', [InvoiceController::class, 'toggleFacturacion'])->name('toggleFacturacion');
        Route::post('/fiscal-profiles', [FiscalProfileController::class, 'storeFiscalProfile'])->name('storeFiscalProfile');
        Route::post('/fiscal-profiles/upload-csd', [FiscalProfileController::class, 'uploadCsd'])->name('uploadCsd');
        Route::delete('/fiscal-profiles/{fiscalProfile}', [FiscalProfileController::class, 'destroy'])->name('destroyFiscalProfile');
        Route::post('/fiscal-profiles/{fiscalProfile}/logo', [FiscalProfileController::class, 'uploadLogo'])->name('uploadLogo');
        Route::delete('/fiscal-profiles/{fiscalProfile}/logo', [FiscalProfileController::class, 'deleteLogo'])->name('deleteLogo');
    });
});
