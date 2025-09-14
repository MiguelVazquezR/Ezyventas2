<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
});

Route::middleware('auth')->group(function () {
    // require __DIR__ . '/web/attendances.php';
    // require __DIR__ . '/web/bonuses.php';
    // require __DIR__ . '/web/branches.php';
    // require __DIR__ . '/web/incidents.php';
    // require __DIR__ . '/web/settings.php';
    // require __DIR__ . '/web/users.php';
    // require __DIR__ . '/web/vacations.php';
});

// Estas rutas NO requieren autenticación.


//artisan commands -------------------
Route::get('/clear-all', function () {
    Artisan::call('optimize:clear');
    return 'cleared.';
});

Route::get('/storage-link', function () {
    Artisan::call('storage:link');
    return 'cleared.';
});