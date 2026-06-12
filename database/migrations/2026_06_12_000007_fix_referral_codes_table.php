<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recrea las tablas referral_codes y referral_usages con el esquema correcto,
     * descartando el esquema antiguo incompatible.
     */
    public function up(): void
    {
        // Primero borramos referral_usages (depende de referral_codes)
        Schema::dropIfExists('referral_usages');
        Schema::dropIfExists('referral_codes');

        // Recreamos referral_codes con el nuevo esquema
        Schema::create('referral_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->string('code', 12)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Recreamos referral_usages con el nuevo esquema
        Schema::create('referral_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_code_id')->constrained('referral_codes')->cascadeOnDelete();
            $table->foreignId('referred_subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('subscription_payment_id')->constrained('subscription_payments')->cascadeOnDelete();
            $table->enum('reward_status', ['pending', 'paid', 'cancelled'])->default('pending');
            $table->decimal('referred_discount_pct', 5, 2);
            $table->decimal('referrer_reward_pct', 5, 2);
            $table->decimal('referrer_ongoing_discount_pct', 5, 2);
            $table->decimal('monthly_base_amount', 10, 2);
            $table->decimal('reward_amount', 10, 2);
            $table->timestamp('reward_paid_at')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();
        });

        // Marcar como ejecutadas las migraciones originales de creación
        // para evitar que intenten recrear las tablas
        DB::table('migrations')->where('migration', '2026_06_12_000002_create_referral_codes_table')->delete();
        DB::table('migrations')->where('migration', '2026_06_12_000003_create_referral_usages_table')->delete();
    }

    public function down(): void
    {
        // No reversible
    }
};

