<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PointOfSaleController;

/*
|--------------------------------------------------------------------------
| Point of Sale (POS) Routes
|--------------------------------------------------------------------------
|
| Aquí se registran todas las rutas para el módulo de punto de venta.
| Este archivo es incluido por routes/web.php, por lo que todas las rutas
| aquí definidas ya cuentan con el middleware de autenticación.
|
*/
Route::middleware('auth')->prefix('pos')->as('pos.')->group(function () {
    Route::get('/', [PointOfSaleController::class, 'index'])->name('index');
    Route::post('/checkout', [PointOfSaleController::class, 'checkout'])->name('checkout');
});
