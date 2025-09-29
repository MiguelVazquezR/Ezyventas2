<?php

use App\Http\Controllers\CustomFieldDefinitionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::resource('custom-field-definitions', CustomFieldDefinitionController::class)->except(['show', 'create', 'edit']);
});