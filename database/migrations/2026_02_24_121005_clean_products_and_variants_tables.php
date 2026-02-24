<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * EJECUTAR ESTA MIGRACIÓN SOLO DESPUÉS DE HABER EJECUTADO LA MIGRACIÓN
     * QUE COPIA LOS DATOS A LAS TABLAS PIVOT (2026_02_23_134006...)
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'location',
                'current_stock',
                'min_stock',
                'max_stock'
            ]);
        });

        Schema::table('product_attributes', function (Blueprint $table) {
            $table->dropColumn([
                'current_stock',
                'min_stock',
                'max_stock'
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->integer('current_stock')->default(0);
            $table->integer('min_stock')->nullable();
            $table->integer('max_stock')->nullable();
            $table->string('location')->nullable();
        });

        Schema::table('product_attributes', function (Blueprint $table) {
            $table->integer('current_stock')->nullable();
            $table->integer('min_stock')->nullable();
            $table->integer('max_stock')->nullable();
        });
    }
};