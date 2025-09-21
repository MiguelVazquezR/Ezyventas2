<?php

use App\Http\Controllers\FinancialControlController;
use Illuminate\Support\Facades\Route;

Route::get('/financial-control', [FinancialControlController::class, 'index'])
    ->middleware('auth')->name('financial-control.index');
