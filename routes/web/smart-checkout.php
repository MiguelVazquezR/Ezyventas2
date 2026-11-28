<?php

use App\Http\Controllers\SmartCheckout\GalleryCatalogController;
use App\Http\Controllers\SmartCheckout\GalleryController;
use App\Http\Controllers\SmartCheckout\ScaleController;
use App\Http\Controllers\SmartCheckout\SmartCheckoutController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Smart checkout / "Modo Caja Inteligente" routes
|--------------------------------------------------------------------------
|
| Loaded from routes/web.php so the routes inherit the `web` middleware group.
| Everything lives under /pos: the feature is part of the Point of Sale and has
| no menu entry of its own.
|
| Gating, in this order:
|  1. the user must be able to use the Point of Sale (`pos.access`, the existing
|     POS permission — no new permissions were created on purpose: these
|     features are available to every POS user),
|  2. business preference, checked inside the controller on every request:
|     `smartpos_settings.scale_enabled` for the scale endpoints and
|     `smartpos_settings.smart_checkout_enabled` for the smart checkout ones.
|
| The exception is `/preferences`: it *is* the on/off switch of the business, so
| it is not gated by the preference and works both to enable and to disable.
|
*/

Route::middleware(['auth', 'can:pos.access'])->prefix('pos/smart-checkout')->as('pos.smart-checkout.')->group(function () {
    // On/off switches of the module. Must keep working while everything is off.
    Route::put('/preferences', [ScaleController::class, 'updatePreferences'])
        ->name('preferences.update');

    Route::get('/scale/settings', [ScaleController::class, 'settings'])
        ->name('scale.settings');

    Route::put('/scale/settings', [ScaleController::class, 'updateSettings'])
        ->name('scale.settings.update');

    Route::get('/scale/weighable-products', [ScaleController::class, 'weighableProducts'])
        ->name('scale.products');

    // "Modo Caja Inteligente" (Phase 2): configuration, metric events and the
    // indicator bar of the day. Same group and same gate as the scale: the
    // business preference is checked inside the controller on every request and
    // the browser's `localStorage` authorises nothing.
    Route::get('/settings', [SmartCheckoutController::class, 'settings'])
        ->name('settings');

    Route::put('/settings', [SmartCheckoutController::class, 'updateSettings'])
        ->name('settings.update');

    // The panel posts one event per analysis: 120 per minute is far above what
    // two cameras can produce and still caps a runaway terminal.
    Route::post('/events', [SmartCheckoutController::class, 'storeEvent'])
        ->name('events.store')
        ->middleware('throttle:120,1');

    Route::get('/metrics/today', [SmartCheckoutController::class, 'todayMetrics'])
        ->name('metrics.today')
        // El panel la consulta tras cada envío de eventos (cada 5 s por terminal):
        // 120 por minuto es media consulta por segundo y sigue cortando un bucle.
        ->middleware('throttle:120,1');

    // "Galería de vectores" (Phase 3): the browser downloads the embeddings of the
    // tenant once and searches by similarity in memory. Same group, same gate, no
    // permission of its own; `smart_checkout_enabled` is checked inside the
    // controller on every request (403 while the mode is off).
    //
    //  - GET answers with an `ETag` and honours `If-None-Match` (304, no body);
    //    `?model_version=` selects the model and `?cursor=` walks the pages of the
    //    gallery when it is larger than ~4 MB. It is the most expensive read of the
    //    module (up to ~4 MB per page), so it is capped at 30 requests per minute:
    //    one activation uses one or three, and a 304 revalidation is free.
    //  - POST is capped at 60 samples per minute and per user: capturing a
    //    product takes a handful of shots, 60 is already a runaway terminal.
    //  - DELETE only reaches samples of the authenticated tenant, and is capped
    //    because it is the only destructive write of the module.
    Route::get('/gallery', [GalleryController::class, 'index'])
        ->name('gallery')
        ->middleware('throttle:30,1');

    Route::post('/samples', [GalleryController::class, 'store'])
        ->name('samples.store')
        ->middleware('throttle:60,1');

    Route::delete('/samples/{sample}', [GalleryController::class, 'destroy'])
        ->name('samples.destroy')
        ->middleware('throttle:60,1');

    // "Galería desde el catálogo" (Phase 4): which products of the branch can be
    // seeded from their catalog photos, page by page, and the samples a product
    // already has. Same group and same gate (403 while the mode is off); both are
    // read-only and capped at 120 requests per minute because the panel walks the
    // catalog in pages and downloads every image separately.
    Route::get('/gallery/candidates', [GalleryCatalogController::class, 'candidates'])
        ->name('gallery.candidates')
        ->middleware('throttle:120,1');

    Route::get('/gallery/products/{product}', [GalleryCatalogController::class, 'productSamples'])
        ->name('gallery.product')
        ->middleware('throttle:120,1')
        // Un id inexistente contesta el mismo 404 JSON en español que un producto
        // de otra sucursal: el binding implícito lanzaría una página HTML.
        ->missing(fn (Request $request) => response()->json([
            'message' => GalleryCatalogController::PRODUCT_NOT_FOUND_MESSAGE,
        ], 404));
});
