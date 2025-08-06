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
        Schema::create('promotion_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->onDelete('cascade');
            $table->string('type')->comment('MINIMUM_CART_TOTAL, REQUIRES_PRODUCT, etc.');
            $table->string('value')->comment('El valor de la condición, ej: 500.00 o el ID de un producto');

            // Para reglas que aplican a un item específico (ej. REQUIRES_PRODUCT)
            $table->nullableMorphs('itemable');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotion_rules');
    }
};