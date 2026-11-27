<?php

use App\Http\Controllers\HelpCenterController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {

    // --- CENTRO DE AYUDA (SUSCRIPTORES) ---
    // Documentación y videotutoriales agrupados por módulo.
    Route::get('/centro-ayuda', [HelpCenterController::class, 'index'])->name('help-center.index');
    Route::get('/centro-ayuda/{module}', [HelpCenterController::class, 'show'])->name('help-center.show');

});
