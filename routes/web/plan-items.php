<?php

use App\Http\Controllers\Admin\PlanItemController;
use Illuminate\Support\Facades\Route;

// Rutas exclusivas para el Super Administrador
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->prefix('admin')->name('admin.')->group(function () {
    
    // Rutas para la gestión de ítems del plan (SaaS)
    Route::resource('plan-items', PlanItemController::class)->names([
        'index'   => 'plan-items.index',
        'create'  => 'plan-items.create',
        'store'   => 'plan-items.store',
        'show'    => 'plan-items.show',
        'edit'    => 'plan-items.edit',
        'update'  => 'plan-items.update',
        'destroy' => 'plan-items.destroy',
    ]);

});