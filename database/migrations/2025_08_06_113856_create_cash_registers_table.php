<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('in_use')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_registers');
    }
};