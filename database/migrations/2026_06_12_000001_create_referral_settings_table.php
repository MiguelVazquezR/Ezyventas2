<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('referred_discount_pct', 5, 2)->default(15.00);
            $table->decimal('referrer_reward_pct', 5, 2)->default(50.00);
            $table->decimal('referrer_ongoing_discount_pct', 5, 2)->default(10.00);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_settings');
    }
};
