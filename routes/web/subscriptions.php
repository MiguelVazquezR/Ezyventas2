<?php

use App\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;

// Asegúrate de que este archivo se carga en tu RouteServiceProvider
// y está protegido por el middleware 'auth'.

Route::middleware(['auth'])->prefix('subscription')->name('subscription.')->group(function () {
    
    // La vista principal que ya tenías
    Route::get('/', [SubscriptionController::class, 'show'])->name('show');
    Route::put('/', [SubscriptionController::class, 'update'])->name('update');
    
    // El endpoint para subir documentos
    Route::post('/documents', [SubscriptionController::class, 'storeDocument'])->name('documents.store');
    
    // El endpoint para solicitar facturas
    Route::post('/payments/{payment}/request-invoice', [SubscriptionController::class, 'requestInvoice'])->name('payments.request-invoice');

    // --- RUTAS NUEVAS ---
    
    /**
     * Muestra la página inteligente para Mejorar o Renovar.
     */
    Route::get('/manage', [SubscriptionController::class, 'manage'])->name('manage');

    /**
     * Procesa el pago/mejora.
     * Un solo endpoint que maneja ambas lógicas (mejora o renovación).
     */
    Route::post('/manage', [SubscriptionController::class, 'processManagement'])->name('manage.store');

});
