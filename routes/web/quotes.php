<?php

use App\Http\Controllers\QuoteController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::post('quotes/batch-destroy', [QuoteController::class, 'batchDestroy'])->name('quotes.batchDestroy');
    Route::patch('quotes/{quote}/status', [QuoteController::class, 'updateStatus'])->name('quotes.updateStatus');
    Route::post('quotes/{quote}/new-version', [QuoteController::class, 'newVersion'])->name('quotes.newVersion');
    Route::get('quotes/{quote}/print', [QuoteController::class, 'print'])->name('quotes.print');
    Route::post('quotes/{quote}/convert-to-sale', [QuoteController::class, 'convertToSale'])->name('quotes.convertToSale');

    Route::resource('quotes', QuoteController::class);
});
