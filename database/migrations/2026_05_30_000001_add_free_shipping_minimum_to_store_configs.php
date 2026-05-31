<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_configs', function (Blueprint $table) {
            $table->decimal('free_shipping_minimum', 10, 2)->nullable()->default(0)
                ->after('delivery_fee')
                ->comment('Minimum order amount for free delivery. 0 means no free shipping threshold.');
        });
    }

    public function down(): void
    {
        Schema::table('store_configs', function (Blueprint $table) {
            $table->dropColumn('free_shipping_minimum');
        });
    }
};
