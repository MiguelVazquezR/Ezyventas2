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
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->string('status')->default('approved')->after('payment_method'); // e.g., pending, approved, rejected
            $table->json('payment_details')->nullable()->after('status'); // Para guardar ID de Stripe, o motivo de rechazo
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropColumn('status');
            $table->dropColumn('payment_details');
        });
    }
};