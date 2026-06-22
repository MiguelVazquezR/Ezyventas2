<?php

use App\Http\Controllers\Admin\AdminSubscriptionPaymentController;
use App\Http\Controllers\Admin\PlanItemController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ReleaseNoteController;
use App\Http\Middleware\CheckSuperAdmin;
use Illuminate\Support\Facades\Route;

// Asegúrate de que este grupo esté protegido por tu middleware de superadmin
// (el que da acceso a tu Usuario ID 1)
Route::middleware(['auth', CheckSuperAdmin::class])->prefix('admin')->name('admin.')->group(function () {
    
    // --- Reportes / Dashboard Super Admin ---
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');

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
    Route::put('subscriptions/versions/{version}', [SubscriptionController::class, 'updateVersion'])->name('subscriptions.update-version');
    Route::put('subscriptions/versions/{version}/items', [SubscriptionController::class, 'updateVersionItems'])->name('subscriptions.update-version-items');
    Route::post('subscriptions/{subscription:id}/versions', [SubscriptionController::class, 'storeVersion'])->name('subscriptions.store-version');
    Route::delete('subscriptions/versions/{version}', [SubscriptionController::class, 'destroyVersion'])->name('subscriptions.destroy-version');
    Route::post('subscriptions/{subscription:id}/settings', [SubscriptionController::class, 'updateSettings'])->name('subscriptions.update-settings');

    // --- Novedades (Release Notes) ---
    Route::prefix('release-notes')->name('release-notes.')->group(function () {
        Route::get('/', [ReleaseNoteController::class, 'adminIndex'])->name('index');
        Route::get('/create', [ReleaseNoteController::class, 'create'])->name('create');
        Route::post('/', [ReleaseNoteController::class, 'store'])->name('store');
        Route::get('/{releaseNote}/edit', [ReleaseNoteController::class, 'edit'])->name('edit');
        Route::put('/{releaseNote}', [ReleaseNoteController::class, 'update'])->name('update');
        Route::delete('/{releaseNote}', [ReleaseNoteController::class, 'destroy'])->name('destroy');
        Route::post('/{releaseNote}/toggle-publish', [ReleaseNoteController::class, 'togglePublish'])->name('toggle-publish');
    });

});