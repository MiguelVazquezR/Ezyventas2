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
        Schema::table('products', function (Blueprint $table) {
            $table->integer('reserved_stock')->default(0)->after('current_stock')->comment('Stock reservado en apartados');
        });

        Schema::table('product_attributes', function (Blueprint $table) {
            $table->integer('reserved_stock')->default(0)->after('current_stock')->comment('Stock reservado en apartados');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('reserved_stock');
        });

        Schema::table('product_attributes', function (Blueprint $table) {
            $table->dropColumn('reserved_stock');
        });
    }
};