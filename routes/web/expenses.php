<?php

use App\Http\Controllers\ExpenseController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::post('expenses/batch-destroy', [ExpenseController::class, 'batchDestroy'])->name('expenses.batchDestroy');
    Route::patch('expenses/{expense}/status', [ExpenseController::class, 'updateStatus'])->name('expenses.updateStatus');
    Route::resource('expenses', ExpenseController::class);
});
