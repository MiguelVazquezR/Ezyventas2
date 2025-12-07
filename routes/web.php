<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OnboardingController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Laravel\Jetstream\Agent;

Route::get('/', function () {
    $agent = new Agent();

    if ($agent->isDesktop() || $agent->isLaptop()) {
        return inertia('Welcome');
    } else {
        return inertia('WelcomeMobile');
    }
});

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // --- INICIO: RUTAS DE ONBOARDING ---
    Route::prefix('onboarding')->name('onboarding.')->group(function () {
        Route::get('/setup', [OnboardingController::class, 'show'])->name('setup');
        Route::post('/step-1', [OnboardingController::class, 'storeStep1'])->name('store.step1');
        Route::post('/step-2', [OnboardingController::class, 'storeStep2'])->name('store.step2');
        Route::post('/step-3', [OnboardingController::class, 'storeStep3'])->name('store.step3');
        Route::post('/finish', [OnboardingController::class, 'finish'])->name('finish');
    });
});

require __DIR__ . '/web/POS.php';
require __DIR__ . '/web/products.php';
require __DIR__ . '/web/products-stock.php';
require __DIR__ . '/web/import-export.php';
require __DIR__ . '/web/quick-create.php';
require __DIR__ . '/web/promotions.php';
require __DIR__ . '/web/base-catalog.php';
require __DIR__ . '/web/expenses.php';
require __DIR__ . '/web/customers.php';
require __DIR__ . '/web/services.php';
require __DIR__ . '/web/service-orders.php';
require __DIR__ . '/web/quotes.php';
require __DIR__ . '/web/financial-control.php';
require __DIR__ . '/web/cash-registers.php';
require __DIR__ . '/web/cash-register-sessions.php';
require __DIR__ . '/web/cash-register-session-movements.php';
require __DIR__ . '/web/transactions.php';
require __DIR__ . '/web/payments.php';
require __DIR__ . '/web/settings.php';
require __DIR__ . '/web/roles.php';
require __DIR__ . '/web/permissions.php';
require __DIR__ . '/web/users.php';
require __DIR__ . '/web/subscriptions.php';
require __DIR__ . '/web/bank-accounts.php';
require __DIR__ . '/web/branches.php';
require __DIR__ . '/web/switch-branch.php';
require __DIR__ . '/web/print-templates.php';
require __DIR__ . '/web/print.php';
require __DIR__ . '/web/custom-field-definitions.php';
require __DIR__ . '/web/reports.php';
require __DIR__ . '/web/google-auth.php';
require __DIR__ . '/web/categories.php';
require __DIR__ . '/web/brands.php';
require __DIR__ . '/web/providers.php';
require __DIR__ . '/web/expense-categories.php';
require __DIR__ . '/web/super-admin.php';
// borrar despues de migrar en producción
// require __DIR__ . '/web/migrate-products.php';
// require __DIR__ . '/web/migrate-customers.php';
// require __DIR__ . '/web/migrate-transactions.php';


//artisan commands -------------------
Route::get('/clear-all', function () {
    Artisan::call('optimize:clear');
    return 'cleared.';
});

Route::get('/storage-link', function () {
    Artisan::call('storage:link');
    return 'cleared.';
});

Route::get('/migrate', function () {
    Artisan::call('migrate');
    return 'migrated!.';
});

Route::get('/fix-so', function () {
    Artisan::call('app:fix-service-order-folios');
    return 'fixed!.';
});
