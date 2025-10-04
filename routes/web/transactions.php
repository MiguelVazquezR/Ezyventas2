<?php

use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function(){
    // Rutas para acciones específicas
    Route::patch('transactions/{transaction}/cancel', [TransactionController::class, 'cancel'])->name('transactions.cancel');
    Route::patch('transactions/{transaction}/refund', [TransactionController::class, 'refund'])->name('transactions.refund');
    
    // Rutas del resource
    Route::resource('transactions', TransactionController::class)->except(['create', 'store', 'edit', 'update', 'destroy']);
});