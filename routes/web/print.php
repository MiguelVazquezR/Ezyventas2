<?php

use App\Http\Controllers\PrintController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::post('/print/payload', [PrintController::class, 'generatePayload'])->name('print.payload');
    Route::post('/print/bluetooth-payload', [PrintController::class, 'bluetoothPayload'])->name('print.bluetooth-payload');
    Route::post('/print/ticket-html', [PrintController::class, 'ticketHtml'])->name('print.ticket-html');
});
