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
        Schema::create('global_products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('sku')->unique()->comment('Stock Keeping Unit');
            
            // Relaciones: si se borra una categoría/marca, el producto no se borra, solo se desvincula.
            $table->foreignId('category_id')->nullable()->constrained('categories')->onDelete('set null');
            $table->foreignId('brand_id')->nullable()->constrained('brands')->onDelete('set null');

            // Precios y Moneda
            $table->decimal('selling_price', 10, 2)->comment('Precio de venta al público');

            // Inventario
            $table->string('measure_unit')->nullable()->comment('ej. pz, kg, lt');
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('global_products');
    }
};
