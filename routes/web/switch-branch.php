<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::put('/switch-branch/{branch}', [SwitchBranchController::class, 'update'])->name('branch.switch');
});