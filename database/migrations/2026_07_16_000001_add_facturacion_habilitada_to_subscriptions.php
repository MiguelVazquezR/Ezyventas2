<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds an opt-in flag for Mexican invoicing (CFDI via SW Sapien).
     * Billing is disabled by default — the subscription owner must
     * explicitly activate it from the billing settings panel.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('facturacion_habilitada')
                ->default(false)
                ->after('referrer_discount_active')
                ->comment('Whether the subscription has opted into CFDI invoicing via SW Sapien');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('facturacion_habilitada');
        });
    }
};
