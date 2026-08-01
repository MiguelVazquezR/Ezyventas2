<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Products table
        Schema::table('products', function (Blueprint $table) {
            $table->string('sat_product_code', 8)->nullable()->after('sku');
            $table->string('sat_unit_code', 10)->nullable()->after('sat_product_code');
        });

        // Services table
        Schema::table('services', function (Blueprint $table) {
            $table->string('sat_product_code', 8)->nullable()->after('name');
            $table->string('sat_unit_code', 10)->nullable()->after('sat_product_code');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['sat_product_code', 'sat_unit_code']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['sat_product_code', 'sat_unit_code']);
        });
    }
};
