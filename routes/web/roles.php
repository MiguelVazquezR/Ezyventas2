<?php

use App\Http\Controllers\RolePermissionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('roles-permissions')->as('roles.')->group(function () {
    Route::get('/', [RolePermissionController::class, 'index'])->name('index');
    Route::post('/', [RolePermissionController::class, 'store'])->name('store');
    Route::put('/{role}', [RolePermissionController::class, 'update'])->name('update');
    Route::delete('/{role}', [RolePermissionController::class, 'destroy'])->name('destroy');
});
