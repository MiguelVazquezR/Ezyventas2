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
        Schema::table('store_configs', function (Blueprint $table) {
            $table->text('mp_access_token')->nullable()->comment('Encrypted Mercado Pago access token');
            $table->text('mp_refresh_token')->nullable()->comment('Encrypted Mercado Pago refresh token');
            $table->string('mp_user_id')->nullable()->comment('Mercado Pago account user ID');
            $table->string('mp_public_key')->nullable()->comment('Mercado Pago public key for frontend SDK');
            $table->timestamp('mp_token_expires_at')->nullable()->comment('When the MP access token expires');
            $table->boolean('payment_mp_enabled')->default(false)->comment('Whether Mercado Pago is enabled');
            $table->boolean('payment_cash_enabled')->default(true)->comment('Whether cash on delivery is enabled');
            $table->text('cash_instructions')->nullable()->comment('Custom instructions for cash payment');
        });
    }

    public function down(): void
    {
        Schema::table('store_configs', function (Blueprint $table) {
            $table->dropColumn([
                'mp_access_token', 'mp_refresh_token', 'mp_user_id',
                'mp_public_key', 'mp_token_expires_at',
                'payment_mp_enabled', 'payment_cash_enabled', 'cash_instructions',
            ]);
        });
    }
};
