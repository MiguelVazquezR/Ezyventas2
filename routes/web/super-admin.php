<?php

use App\Http\Controllers\Admin\AdminStampDashboardController;
use App\Http\Controllers\Admin\AdminStampPurchaseController;
use App\Http\Controllers\Admin\AdminStampPricingController;
use App\Http\Controllers\Admin\AdminSubscriptionPaymentController;
use App\Http\Controllers\Admin\AiAgentSettingsController;
use App\Http\Controllers\Admin\PlanItemController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ReleaseNoteController;
use App\Http\Controllers\Admin\SuggestionController;
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

    // ──── Panel Global de Timbres ──────────────────────────
    Route::get('stamps', [AdminStampDashboardController::class, 'index'])->name('stamps.index');
    Route::get('stamps/master-balance', [AdminStampDashboardController::class, 'masterBalance'])->name('stamps.master-balance');
    Route::get('stamps/global-stats', [AdminStampDashboardController::class, 'globalStats'])->name('stamps.global-stats');
    Route::post('stamps/global-stats/refresh', [AdminStampDashboardController::class, 'refreshGlobalStats'])->name('stamps.global-stats.refresh');
    Route::get('stamps/issuers', [AdminStampDashboardController::class, 'issuersIndex'])->name('stamps.issuers.index');
    Route::get('stamps/movements', [AdminStampDashboardController::class, 'movements'])->name('stamps.movements');
    Route::post('stamps/threshold', [AdminStampDashboardController::class, 'updateThreshold'])->name('stamps.threshold.update');

    // ──── Bandeja de Revisión ──────────────────────────────
    Route::get('stamps/review-queue', [AdminStampPurchaseController::class, 'index'])->name('stamps.review-queue');
    // Redirect the old standalone adjust page to the index (modal is used instead).
    Route::redirect('stamps/adjust', 'admin/stamps')->name('stamps.adjust-form');
    Route::post('stamps/{purchase}/approve', [AdminStampPurchaseController::class, 'approve'])->name('stamps.approve');
    Route::post('stamps/{purchase}/reject', [AdminStampPurchaseController::class, 'reject'])->name('stamps.reject');
    Route::post('stamps/{purchase}/retry', [AdminStampPurchaseController::class, 'retry'])->name('stamps.retry');
    Route::post('stamps/manual-adjustment', [AdminStampPurchaseController::class, 'manualAdjustment'])->name('stamps.manual-adjustment');
    Route::get('stamps/balance/{fiscalProfile}', [AdminStampPurchaseController::class, 'balance'])->name('stamps.balance');
    Route::get('stamps/history/{fiscalProfile}', [AdminStampPurchaseController::class, 'history'])->name('stamps.history');

    // ──── Precios de Timbres (CRUD de tramos) ──────────────
    Route::get('stamps/pricing-tiers', [AdminStampPricingController::class, 'index'])->name('stamps.pricing.index');
    Route::post('stamps/pricing-tiers', [AdminStampPricingController::class, 'store'])->name('stamps.pricing.store');
    Route::put('stamps/pricing-tiers/{tier}', [AdminStampPricingController::class, 'update'])->name('stamps.pricing.update');
    Route::delete('stamps/pricing-tiers/{tier}', [AdminStampPricingController::class, 'destroy'])->name('stamps.pricing.destroy');

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

    // --- Sugerencias / Feedback de usuarios ---
    Route::prefix('suggestions')->name('suggestions.')->group(function () {
        Route::get('/', [SuggestionController::class, 'index'])->name('index');
        Route::put('/{suggestion}/status', [SuggestionController::class, 'updateStatus'])->name('update-status');
        Route::put('/{suggestion}/priority', [SuggestionController::class, 'updatePriority'])->name('update-priority');
        Route::put('/{suggestion}/notes', [SuggestionController::class, 'updateAdminNotes'])->name('update-notes');
    });

    // --- Asistente IA (Configuración global) ---
    Route::get('/ai-agent', [AiAgentSettingsController::class, 'index'])->name('ai-agent.index');
    Route::put('/ai-agent', [AiAgentSettingsController::class, 'update'])->name('ai-agent.update');

});