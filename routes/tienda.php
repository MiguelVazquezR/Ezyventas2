<?php

use App\Http\Controllers\Store\PublicStoreController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Store Routes (Tienda Pública)
|--------------------------------------------------------------------------
|
| These routes are accessed by the end customers of the subscriber.
| In path mode: /store/{slug}/...
| In subdomain mode: {slug}.domain.com/...
|
| The middleware 'resolve.store' extracts the store from the URL and
| makes it available throughout the request lifecycle.
*/

Route::middleware(['web', 'resolve.store'])->prefix('store/{slug}')->name('store.')->group(function () {

    // Catalog / Home
    Route::get('/', [PublicStoreController::class, 'index'])->name('home');

    // Product detail
    Route::get('/product/{product}', [PublicStoreController::class, 'show'])->name('product.show');

    // Cart / Order form
    Route::get('/cart', [PublicStoreController::class, 'cart'])->name('cart');

    // Submit order
    Route::post('/order', [PublicStoreController::class, 'placeOrder'])->name('order.place');

    // Order confirmation
    Route::get('/order/{order}/confirmed', [PublicStoreController::class, 'confirmed'])->name('order.confirmed');

    // Policies
    Route::get('/policies', [PublicStoreController::class, 'policies'])->name('policies');
});
