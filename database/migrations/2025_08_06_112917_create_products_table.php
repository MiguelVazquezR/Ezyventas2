<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('sku')->unique()->comment('Stock Keeping Unit');
            
            // Relaciones: si se borra una categoría/marca, el producto no se borra, solo se desvincula.
            $table->foreignId('category_id')->nullable()->constrained('categories')->onDelete('set null');
            $table->foreignId('brand_id')->nullable()->constrained('brands')->onDelete('set null');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->onDelete('set null');
            $table->foreignId('global_product_id')->nullable()->constrained('global_products')->onDelete('set null');
            $table->foreignId('provider_id')->nullable()->constrained('providers')->onDelete('set null');

            // Precios y Moneda
            $table->decimal('selling_price', 10, 2)->comment('Precio de venta al público');
            $table->decimal('cost_price', 10, 2)->nullable()->comment('Lo que le cuesta al negocio');
            $table->string('currency', 3)->default('MXN');

            // Inventario
            $table->integer('current_stock')->default(0);
            $table->integer('min_stock')->nullable();
            $table->integer('max_stock')->nullable();
            $table->string('measure_unit')->nullable()->comment('ej. pz, kg, lt');

            // Atributos para Tienda en Línea
            $table->boolean('show_online')->default(false);
            $table->decimal('online_price', 10, 2)->nullable()->comment('Precio especial para venta online');
            $table->string('slug')->nullable()->unique();
            $table->integer('delivery_days')->nullable();
            $table->json('tags')->nullable();
            $table->boolean('is_featured')->default(false)->comment('Producto destacado');
            $table->boolean('is_on_sale')->default(false)->comment('Producto en oferta');
            $table->decimal('sale_price', 10, 2)->nullable();
            $table->timestamp('sale_start_date')->nullable();
            $table->timestamp('sale_end_date')->nullable();

            // Atributos para Envío
            $table->boolean('requires_shipping')->default(true);
            $table->decimal('weight', 8, 2)->nullable()->comment('en gramos');
            $table->decimal('length', 8, 2)->nullable()->comment('en cm');
            $table->decimal('width', 8, 2)->nullable()->comment('en cm');
            $table->decimal('height', 8, 2)->nullable()->comment('en cm');

            // Analítica
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('purchase_count')->default(0);

            // Misceláneos
            // $table->string('business_type')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};