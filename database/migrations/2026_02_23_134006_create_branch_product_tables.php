<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Crear tabla pivot para Productos Simples
        Schema::create('branch_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            
            // Stock y Ubicación físicos por sucursal
            $table->decimal('current_stock', 10, 2)->default(0);
            $table->decimal('reserved_stock', 10, 2)->default(0);
            $table->decimal('min_stock', 10, 2)->nullable();
            $table->decimal('max_stock', 10, 2)->nullable();
            $table->string('location')->nullable()->comment('Estante/Pasillo en esta sucursal específica');
            
            $table->timestamps();
            $table->unique(['branch_id', 'product_id']);
        });

        // 2. Crear tabla pivot para Variantes de Productos
        Schema::create('branch_product_attribute', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_attribute_id')->constrained('product_attributes')->cascadeOnDelete();
            
            // Stock y Ubicación físicos por sucursal para la variante
            $table->decimal('current_stock', 10, 2)->default(0);
            $table->decimal('reserved_stock', 10, 2)->default(0);
            $table->decimal('min_stock', 10, 2)->nullable();
            $table->decimal('max_stock', 10, 2)->nullable();
            $table->string('location')->nullable();
            
            $table->timestamps();
            $table->unique(['branch_id', 'product_attribute_id']);
        });

        // =========================================================
        // 3. MIGRACIÓN DE DATOS (Para no perder el inventario actual)
        // =========================================================

        // Migrar stock de productos simples
        DB::table('products')->orderBy('id')->chunk(500, function ($products) {
            $productPivots = [];
            foreach ($products as $p) {
                // Verificamos que tenga branch_id asignado en el producto antiguo
                if ($p->branch_id) {
                    $productPivots[] = [
                        'branch_id' => $p->branch_id,
                        'product_id' => $p->id,
                        'current_stock' => $p->current_stock ?? 0,
                        'reserved_stock' => $p->reserved_stock ?? 0, // Si existía en tu DB actual
                        'min_stock' => $p->min_stock ?? null,
                        'max_stock' => $p->max_stock ?? null,
                        'location' => $p->location ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
            if (!empty($productPivots)) {
                DB::table('branch_product')->insert($productPivots);
            }
        });

        // Migrar stock de variantes de producto
        if (Schema::hasTable('product_attributes')) {
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
                            'reserved_stock' => $a->reserved_stock ?? 0, // Si existía
                            'min_stock' => $a->min_stock ?? null, // Ahora migramos los límites de la variante
                            'max_stock' => $a->max_stock ?? null,
                            'location' => null, // Históricamente las variantes no tenían ubicación, inicia nulo
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }
                if (!empty($attrPivots)) {
                    DB::table('branch_product_attribute')->insert($attrPivots);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_product_attribute');
        Schema::dropIfExists('branch_product');
    }
};