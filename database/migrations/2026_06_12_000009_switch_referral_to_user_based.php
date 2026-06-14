<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cambia el sistema de referidos de subscription-based a user-based.
     */
    public function up(): void
    {
        // Limpiar datos existentes que apuntaban al esquema antiguo
        DB::table('referral_usages')->delete();
        DB::table('referral_codes')->delete();
        DB::table('referrer_bank_accounts')->delete();

        // 1. referral_codes: subscription_id → user_id
        Schema::table('referral_codes', function (Blueprint $table) {
            if (Schema::hasColumn('referral_codes', 'subscription_id')) {
                $table->dropForeign(['subscription_id']);
                $table->dropColumn('subscription_id');
            }
            if (!Schema::hasColumn('referral_codes', 'user_id')) {
                $table->foreignId('user_id')->after('id')->constrained('users')->cascadeOnDelete();
            }
        });

        // 2. referrer_bank_accounts: subscription_id → user_id
        Schema::table('referrer_bank_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('referrer_bank_accounts', 'subscription_id')) {
                $table->dropForeign(['subscription_id']);
                $table->dropUnique(['subscription_id']);
                $table->dropColumn('subscription_id');
            }
            if (!Schema::hasColumn('referrer_bank_accounts', 'user_id')) {
                $table->foreignId('user_id')->after('id')->unique()->constrained('users')->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        // No reversible
    }
};
