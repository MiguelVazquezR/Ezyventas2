<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained('affiliates')->onDelete('cascade');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->string('discount_type'); // percentage, fixed_amount
            $table->decimal('discount_value', 10, 2);
            $table->string('commission_type'); // percentage, fixed_amount
            $table->decimal('commission_value', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_codes');
    }
};