<?php

use App\Http\Controllers\QuickCreateController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('quick-create')->as('quick-create.')->group(function () {
    Route::post('categories', [QuickCreateController::class, 'storeCategory'])->name('categories.store');
    Route::post('brands', [QuickCreateController::class, 'storeBrand'])->name('brands.store');
    Route::post('providers', [QuickCreateController::class, 'storeProvider'])->name('providers.store');
    Route::post('expense_categories', [QuickCreateController::class, 'storeExpenseCategory'])->name('expense_categories.store');
    Route::post('customers', [QuickCreateController::class, 'storeCustomer'])->name('customers.store');
    Route::post('/products', [QuickCreateController::class, 'storeProduct'])->name('products.store');
});
