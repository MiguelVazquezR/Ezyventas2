<?php

use App\Http\Controllers\PrintTemplateController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // Ruta para la subida de imágenes
    Route::post('/print-templates/media', [PrintTemplateController::class, 'storeMedia'])->name('print-templates.media.store');
    
    // Rutas del recurso para el CRUD de plantillas
    Route::resource('print-templates', PrintTemplateController::class);
});