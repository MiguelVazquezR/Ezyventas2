<?php

use App\Http\Controllers\PrintController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::post('/print/payload', [PrintController::class, 'generatePayload'])->name('print.payload');
});