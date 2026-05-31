<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_configs', function (Blueprint $table) {
            $table->boolean('allow_out_of_stock_purchases')->default(false)->after('accepts_delivery')->comment('Allow customers to buy out-of-stock products');
            $table->integer('out_of_stock_extra_minutes')->nullable()->default(null)->after('allow_out_of_stock_purchases')->comment('Extra preparation minutes when restocking is needed');
            $table->string('whatsapp_number', 20)->nullable()->after('out_of_stock_extra_minutes')->comment('Store WhatsApp contact number');
            $table->string('tagline', 120)->nullable()->after('whatsapp_number')->comment('Short store slogan shown below the name');
        });
    }

    public function down(): void
    {
        Schema::table('store_configs', function (Blueprint $table) {
            $table->dropColumn(['allow_out_of_stock_purchases', 'out_of_stock_extra_minutes', 'whatsapp_number', 'tagline']);
        });
    }
};
