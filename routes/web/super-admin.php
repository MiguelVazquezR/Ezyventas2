<?php

use App\Http\Controllers\Admin\AdminSubscriptionPaymentController;
use App\Http\Middleware\CheckSuperAdmin;
use Illuminate\Support\Facades\Route;

// ... (tus otras rutas de admin) ...

// Asegúrate de que este grupo esté protegido por tu middleware de superadmin
// (el que da acceso a tu Usuario ID 1)
Route::middleware(['auth', CheckSuperAdmin::class])->prefix('admin')->name('admin.')->group(function () {
    
    // Pagos Pendientes
    Route::get('payments', [AdminSubscriptionPaymentController::class, 'index'])->name('payments.index');
    Route::get('payments/{payment}', [AdminSubscriptionPaymentController::class, 'show'])->name('payments.show');
    Route::post('payments/{payment}/approve', [AdminSubscriptionPaymentController::class, 'approve'])->name('payments.approve');
    Route::post('payments/{payment}/reject', [AdminSubscriptionPaymentController::class, 'reject'])->name('payments.reject');

    // Aquí irían tus otras rutas de admin, como la gestión de PlanItems, etc.
});