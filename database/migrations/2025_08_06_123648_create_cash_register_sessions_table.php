<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_register_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_register_id')->constrained('cash_registers')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->string('status'); // abierta, cerrada
            $table->decimal('opening_cash_balance', 10, 2);
            $table->decimal('closing_cash_balance', 10, 2)->nullable();
            $table->decimal('calculated_cash_total', 10, 2)->nullable();
            $table->decimal('cash_difference', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_register_sessions');
    }
};