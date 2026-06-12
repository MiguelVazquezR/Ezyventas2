<?php

use App\Http\Controllers\Admin\ReleaseNoteController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {

    // --- RUTAS PARA SUSCRIPTORES (CLIENTES) ---
    // Estas rutas son para que los usuarios normales vean las novedades y las marquen como leídas.
    Route::get('/release-notes', [ReleaseNoteController::class, 'index'])->name('release-notes.index');
    Route::post('/release-notes/mark-all-read', [ReleaseNoteController::class, 'markAllAsRead'])->name('release-notes.mark-all-read');
    Route::post('/release-notes/{releaseNote}/mark-read', [ReleaseNoteController::class, 'markAsRead'])->name('release-notes.mark-read');
    Route::get('/release-notes/{releaseNote}', [ReleaseNoteController::class, 'show'])->name('release-notes.show');

});