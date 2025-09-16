<?php

use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del Módulo de Productos
|--------------------------------------------------------------------------
*/

Route::resource('products', ProductController::class)->only(['index', 'create', 'store']);