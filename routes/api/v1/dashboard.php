<?php

use App\Http\Controllers\Api\V1\Dashboard\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API v1 — Dashboard (home screen)
|--------------------------------------------------------------------------
|
| KPIs, alerts and the state of the cash register of the branch the user is
| working in. Every block is gated by its own permission: see the permission
| table of docs/mobile-app/01-contrato-api-v1.md.
|
*/

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->name('dashboard.index');

Route::get('/dashboard/expiring-layaways', [DashboardController::class, 'expiringLayaways'])
    ->name('dashboard.expiring-layaways');

Route::get('/dashboard/upcoming-deliveries', [DashboardController::class, 'upcomingDeliveries'])
    ->name('dashboard.upcoming-deliveries');
