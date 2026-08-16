<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_monthly', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedInteger('credits_used')->default(0);
            $table->unsignedBigInteger('total_tokens')->default(0);
            $table->decimal('estimated_cost_usd', 10, 4)->default(0);
            $table->timestamps();
            $table->unique(['subscription_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_monthly');
    }
};
