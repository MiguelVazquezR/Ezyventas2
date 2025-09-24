<?php

use App\Http\Controllers\PermissionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::resource('permissions', PermissionController::class)->only([
        'store', 'update', 'destroy'
    ]);
});