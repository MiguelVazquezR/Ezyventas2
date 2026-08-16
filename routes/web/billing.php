<?php

use App\Http\Controllers\Billing\FiscalProfileController;
use App\Http\Controllers\Billing\InvoiceController;
use App\Http\Controllers\Billing\StampPurchaseController;
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
        Route::get('/sales/search', [InvoiceController::class, 'salesSearch'])->name('sales.search');
        Route::get('/sales/{transaction}', [InvoiceController::class, 'salesShow'])->name('sales.show');
        Route::get('/{invoice}/edit', [InvoiceController::class, 'edit'])->name('edit');
        Route::put('/{invoice}', [InvoiceController::class, 'update'])->name('update');
        Route::get('/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('pdf');
        Route::get('/{invoice}/xml', [InvoiceController::class, 'downloadXml'])->name('xml');
        Route::get('/{invoice}', [InvoiceController::class, 'show'])->name('show');
        Route::post('/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('cancel');
        Route::post('/{invoice}/check-cancelation', [InvoiceController::class, 'checkCancelationStatus'])->name('checkCancelation');
        Route::post('/{invoice}/stamp', [InvoiceController::class, 'stamp'])->name('stamp');
        Route::delete('/{invoice}', [InvoiceController::class, 'destroy'])->name('destroy');
    });

    // ── Fiscal Profiles (detail, stamp purchases & manifest) ─────────
    Route::prefix('fiscal-profiles')->name('fiscal-profiles.')->group(function () {
        Route::get('/{fiscalProfile}', [FiscalProfileController::class, 'show'])->name('show');

        // Stamp purchasing
        Route::post('/{fiscalProfile}/stamps/quote', [StampPurchaseController::class, 'quote'])->name('stamps.quote');
        Route::post('/{fiscalProfile}/stamps', [StampPurchaseController::class, 'store'])->name('stamps.store');
        Route::get('/{fiscalProfile}/stamps/return', [StampPurchaseController::class, 'return'])->name('stamps.return');

        // Manifest signing (three-step flow)
        Route::post('/{fiscalProfile}/manifest/fetch-legend', [FiscalProfileController::class, 'fetchManifestLegend'])->name('manifest.fetch-legend');
        Route::post('/{fiscalProfile}/manifest/accept-text', [FiscalProfileController::class, 'acceptManifestText'])->name('manifest.accept-text');
        Route::post('/{fiscalProfile}/manifest/sign', [FiscalProfileController::class, 'signManifest'])->name('manifest.sign');
        Route::get('/{fiscalProfile}/manifest/download', [FiscalProfileController::class, 'downloadManifest'])->name('manifest.download');
    });

    // ── Settings (Fiscal profiles & CSD) ───────────────────
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [InvoiceController::class, 'settings'])->name('index');
        Route::post('/fiscal-profiles', [FiscalProfileController::class, 'storeFiscalProfile'])->name('storeFiscalProfile');
        Route::post('/fiscal-profiles/upload-csd', [FiscalProfileController::class, 'uploadCsd'])->name('uploadCsd');
        Route::delete('/fiscal-profiles/{fiscalProfile}', [FiscalProfileController::class, 'destroy'])->name('destroyFiscalProfile');
        Route::post('/fiscal-profiles/{fiscalProfile}/toggle-active', [FiscalProfileController::class, 'toggleActive'])->name('toggleFiscalProfileActive');
        Route::post('/fiscal-profiles/{fiscalProfile}/logo', [FiscalProfileController::class, 'uploadLogo'])->name('uploadLogo');
        Route::delete('/fiscal-profiles/{fiscalProfile}/logo', [FiscalProfileController::class, 'deleteLogo'])->name('deleteLogo');
    });
});
