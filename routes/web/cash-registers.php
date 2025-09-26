<?php

use App\Http\Controllers\CashRegisterController;
use Illuminate\Support\Facades\Route;

// Usamos un slug amigable para las rutas
Route::resource('cash-registers', CashRegisterController::class)
    ->middleware('auth');
