<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_usages');
    }
};
