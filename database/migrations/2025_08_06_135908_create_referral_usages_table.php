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
            $table->foreignId('referral_code_id')->constrained('referral_codes')->onDelete('cascade');
            $table->foreignId('subscription_payment_id')->constrained('subscription_payments')->onDelete('cascade');
            $table->decimal('commission_earned', 10, 2);
            $table->string('status')->default('pago_pendiente'); // pagado, cancelado
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_usages');
    }
};