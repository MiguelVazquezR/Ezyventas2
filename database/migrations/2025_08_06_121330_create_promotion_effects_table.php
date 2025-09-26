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
        Schema::create('promotion_effects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->onDelete('cascade');
            $table->string('type')->comment('FIXED_DISCOUNT, PERCENTAGE_DISCOUNT, etc.');
            $table->decimal('value', 10, 2)->comment('El monto o porcentaje del descuento');

            // Para efectos que aplican a un item específico (ej. FREE_ITEM)
            $table->nullableMorphs('itemable');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotion_effects');
    }
};