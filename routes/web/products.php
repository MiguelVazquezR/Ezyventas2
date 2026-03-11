<?php

use App\Http\Controllers\AttributeDefinitionController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del Módulo de Productos
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    Route::post('products/batch-destroy', [ProductController::class, 'batchDestroy'])->name('products.batchDestroy');
    Route::post('products/update-price-pos', [ProductController::class, 'updatePriceFromPOS'])->name('products.update-price-pos');
    // SCRIPT TEMPORAL DE MIGRACIÓN (Ejecutar antes de la migración de limpieza)
    Route::get('products/migrate-stock-to-pivot', function () {
        DB::transaction(function () {
            // 1. Migrar Productos Simples
            DB::table('products')->whereNotNull('branch_id')->orderBy('id')->chunk(500, function ($products) {
                $pivots = [];
                foreach ($products as $p) {
                    $pivots[] = [
                        'branch_id' => $p->branch_id,
                        'product_id' => $p->id,
                        'current_stock' => $p->current_stock ?? 0,
                        'reserved_stock' => $p->reserved_stock ?? 0,
                        'min_stock' => $p->min_stock ?? null,
                        'max_stock' => $p->max_stock ?? null,
                        'location' => $p->location ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                if (!empty($pivots)) {
                    DB::table('branch_product')->insertOrIgnore($pivots);
                }
            });

            // 2. Migrar Variantes (Product Attributes)
            DB::table('product_attributes')->orderBy('id')->chunk(500, function ($attributes) {
                $attrPivots = [];
                foreach ($attributes as $a) {
                    // Buscar la sucursal del producto padre
                    $parentProduct = DB::table('products')->where('id', $a->product_id)->first();
                    if ($parentProduct && $parentProduct->branch_id) {
                        $attrPivots[] = [
                            'branch_id' => $parentProduct->branch_id,
                            'product_attribute_id' => $a->id,
                            'current_stock' => $a->current_stock ?? 0,
                            'reserved_stock' => $a->reserved_stock ?? 0,
                            'min_stock' => $a->min_stock ?? null,
                            'max_stock' => $a->max_stock ?? null,
                            'location' => null, // Las variantes históricamente no tenían ubicación
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }
                if (!empty($attrPivots)) {
                    DB::table('branch_product_attribute')->insertOrIgnore($attrPivots);
                }
            });
        });

        return "✅ Migración de stock a tablas pivot completada con éxito. Revisa que todo esté correcto en la plataforma y luego ejecuta 'php artisan migrate' para limpiar las columnas obsoletas.";
    });
    Route::resource('products', ProductController::class);
    Route::resource('attribute-definitions', AttributeDefinitionController::class)->except([
        'create',
        'edit'
    ]);
});
