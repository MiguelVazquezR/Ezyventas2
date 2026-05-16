<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Añadimos is_bulk a la tabla products
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_bulk')->default(false)->after('show_in_pos')->comment('Indica si el producto se vende fraccionado a granel');
        });

        // 2. Cambiamos el tipo de dato a decimal(10,3) para permitir hasta 3 decimales (ej. 1.250 Kg) en el stock de sucursales
        Schema::table('branch_product', function (Blueprint $table) {
            $table->decimal('current_stock', 10, 3)->default(0)->change();
            $table->decimal('min_stock', 10, 3)->nullable()->change();
            $table->decimal('max_stock', 10, 3)->nullable()->change();
            $table->decimal('reserved_stock', 10, 3)->default(0)->change();
        });

        // 3. Cambiamos también los tipos para las variantes (por si alguna vez quieres variantes a granel)
        Schema::table('branch_product_attribute', function (Blueprint $table) {
            $table->decimal('current_stock', 10, 3)->default(0)->change();
            $table->decimal('min_stock', 10, 3)->nullable()->change();
            $table->decimal('max_stock', 10, 3)->nullable()->change();
            $table->decimal('reserved_stock', 10, 3)->default(0)->change();
        });
        
        // 4. Aseguramos que los Items de Transacciones también soporten decimales
        Schema::table('transactions_items', function (Blueprint $table) {
            $table->decimal('quantity', 10, 3)->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_bulk');
        });

        // Para revertir las columnas a integer, depende de tu motor de BD, 
        // pero puedes forzar el 'integer' o dejarlo en decimal si no estorba.
    }
};