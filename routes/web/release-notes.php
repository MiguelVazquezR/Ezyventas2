<?php

use App\Http\Controllers\ReleaseNoteController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {

    // --- RUTAS PARA SUSCRIPTORES (CLIENTES) ---
    // Estas rutas son para que los usuarios normales vean las novedades y las marquen como leídas.
    Route::get('/release-notes', [ReleaseNoteController::class, 'index'])->name('release-notes.index');
    Route::post('/release-notes/mark-all-read', [ReleaseNoteController::class, 'markAllAsRead'])->name('release-notes.mark-all-read');
    Route::post('/release-notes/{releaseNote}/mark-read', [ReleaseNoteController::class, 'markAsRead'])->name('release-notes.mark-read');
    
    // NUEVA RUTA PARA VER EL DETALLE
    Route::get('/release-notes/{releaseNote}', [ReleaseNoteController::class, 'show'])->name('release-notes.show');

    // --- RUTAS PARA ADMINISTRACIÓN (SUPER ADMIN) ---
    // Aquí registramos las rutas de panel administrativo que utiliza el AppMenu y el Index.vue
    Route::prefix('admin/release-notes')->name('admin.release-notes.')->group(function () {
        Route::get('/', [ReleaseNoteController::class, 'adminIndex'])->name('index');
        Route::get('/create', [ReleaseNoteController::class, 'create'])->name('create');
        Route::post('/', [ReleaseNoteController::class, 'store'])->name('store');
        Route::get('/{releaseNote}/edit', [ReleaseNoteController::class, 'edit'])->name('edit');
        Route::put('/{releaseNote}', [ReleaseNoteController::class, 'update'])->name('update');
        Route::delete('/{releaseNote}', [ReleaseNoteController::class, 'destroy'])->name('destroy');
        
        // Ruta adicional para el botón de "Publicar / Ocultar"
        Route::post('/{releaseNote}/toggle-publish', [ReleaseNoteController::class, 'togglePublish'])->name('toggle-publish');
    });

});