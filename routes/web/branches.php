<?php

use App\Http\Controllers\BranchController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::resource('branches', BranchController::class)->only([
        'store', 'update', 'destroy'
    ]);
});