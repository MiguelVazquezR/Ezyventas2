<?php

use App\Http\Controllers\Admin\AdminSubscriptionPaymentController;
use App\Http\Controllers\Admin\PlanItemController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Middleware\CheckSuperAdmin;
use Illuminate\Support\Facades\Route;

// Asegúrate de que este grupo esté protegido por tu middleware de superadmin
// (el que da acceso a tu Usuario ID 1 o rol SuperAdmin)
Route::middleware(['auth', CheckSuperAdmin::class])->prefix('admin')->name('admin.')->group(function () {
    
    // --- Pagos Pendientes ---
    Route::get('payments', [AdminSubscriptionPaymentController::class, 'index'])->name('payments.index');
    Route::get('payments/{payment}', [AdminSubscriptionPaymentController::class, 'show'])->name('payments.show');
    Route::post('payments/{payment}/approve', [AdminSubscriptionPaymentController::class, 'approve'])->name('payments.approve');
    Route::post('payments/{payment}/reject', [AdminSubscriptionPaymentController::class, 'reject'])->name('payments.reject');

    // --- Ítems de Planes (Módulos y Límites del SaaS) ---
    Route::resource('plan-items', PlanItemController::class)->names([
        'index'   => 'plan-items.index',
        'create'  => 'plan-items.create',
        'store'   => 'plan-items.store',
        'show'    => 'plan-items.show',
        'edit'    => 'plan-items.edit',
        'update'  => 'plan-items.update',
        'destroy' => 'plan-items.destroy',
    ]);

    // --- Gestión de Suscriptores (SaaS) ---
    Route::resource('subscriptions', SubscriptionController::class)->only([
        'index', 'show'
    ]);
    
    // --- Actualización manual de vigencia y límites ---
    Route::put('subscriptions/versions/{version}', [SubscriptionController::class, 'updateVersion'])->name('subscriptions.update-version');

});