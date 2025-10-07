<?php

use App\Http\Controllers\FinancialReportController;
use Illuminate\Support\Facades\Route;

Route::get('/financial-control', [FinancialReportController::class, 'index'])
    ->middleware('auth')->name('financial-control.index');
