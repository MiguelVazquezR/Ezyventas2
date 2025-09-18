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
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type')->comment('ITEM_DISCOUNT, CART_DISCOUNT, etc.');
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('usage_limit')->nullable()->comment('Cuántas veces se puede usar en total');
            $table->integer('priority')->default(0)->comment('Prioridad de aplicación (menor número = mayor prioridad)');
            $table->boolean('is_exclusive')->default(false)->comment('Si es true, no se puede combinar con otras');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};