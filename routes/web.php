<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OnboardingController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Laravel\Jetstream\Agent;
use Illuminate\Http\Request;
use App\Models\Waitlist;

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
    Route::get('/dashboard/expiring-layaways', [DashboardController::class, 'getExpiringLayaways'])->name('dashboard.expiring-layaways');
    Route::get('/dashboard/upcoming-deliveries', [DashboardController::class, 'getUpcomingDeliveries'])->name('dashboard.upcoming-deliveries'); 

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

Route::get('/centro-ayuda', function () {
    return Inertia::render('HelpCenter');
})->name('help-center');

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

Route::post('/unirse-lista', function (Request $request) {
    // 1. Validar que es un correo y que no esté repetido
    $request->validate([
        'email' => 'required|email|unique:waitlists,email'
    ]);

    // 2. Crear el registro en la base de datos
    Waitlist::create([
        'email' => $request->email
    ]);

    // 3. Responder que todo salió bien
    return response()->json(['message' => 'Guardado con éxito']);
});

// --- RUTA DE LIMPIEZA SOLICITADA ---
Route::get('/cleanup-products-branch-7', function () {
    $targetBranchId = 7;

    // 1. Buscar los productos
    $products = \App\Models\Product::query()
        ->where('branch_id', $targetBranchId)
        ->where('current_stock', '<=', 0) // Sin stock (0 o negativo)
        ->whereDoesntHave('transactionItems', function ($query) use ($targetBranchId) {
            // Verificamos la relación polimórfica (transactionItems)
            // Y nos aseguramos de que la transacción padre no sea de la sucursal 7
            $query->whereHas('transaction', function ($q) use ($targetBranchId) {
                $q->where('branch_id', $targetBranchId);
            });
        })
        ->get();

    $count = $products->count();

    // 2. Eliminar (Iteramos para disparar eventos de Spatie Media Library/ActivityLog si es necesario)
    \Illuminate\Support\Facades\DB::transaction(function () use ($products) {
        foreach ($products as $product) {
            $product->delete();
        }
    });

    return "Limpieza completada: Se han eliminado {$count} productos de la sucursal {$targetBranchId} que no tenían stock ni historial de ventas en dicha sucursal.";
});

// --- RUTA PARA ESTABLECER VENCIMIENTOS (30 DÍAS) ---
Route::get('/fix-layaway-expiration-dates', function () {
    $count = 0;
    
    // Buscamos transacciones pendientes o en apartado (créditos no liquidados)
    // Se procesa en chunks para evitar problemas de memoria con muchos registros
    \App\Models\Transaction::query()
        ->whereIn('status', [
            \App\Enums\TransactionStatus::PENDING, 
        ])
        ->chunkById(200, function ($transactions) use (&$count) {
            foreach ($transactions as $transaction) {
                // Calcular fecha: Fecha de creación + 30 días
                // Usamos copy() para no modificar la instancia original de created_at si fuera necesario
                $newExpirationDate = \Carbon\Carbon::parse($transaction->created_at)->addDays(30);
                
                $transaction->update([
                    'layaway_expiration_date' => $newExpirationDate
                ]);
                
                $count++;
            }
        });

    return "Proceso completado: Se actualizaron las fechas de vencimiento de {$count} ventas pendientes a 30 días posteriores de su creación.";
});