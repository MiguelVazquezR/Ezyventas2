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
Route::middleware('auth')->group(function () {
    Route::get('/pos', [PointOfSaleController::class, 'index'])->name('pos.index');
});

// Aquí se agregarán futuras rutas del POS, como:
// Route::post('/pos/cart/add', [PointOfSaleController::class, 'addToCart'])->name('pos.cart.add');
// Route::get('/pos/pending-carts', [PointOfSaleController::class, 'getPendingCarts'])->name('pos.pending-carts');
