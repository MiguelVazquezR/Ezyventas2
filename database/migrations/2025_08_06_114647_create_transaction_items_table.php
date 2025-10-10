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
        Schema::create('transactions_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('transactions')->onDelete('cascade');
            
            // Relación polimórfica: un item puede ser un Product, Service, etc.
            $table->nullableMorphs('itemable'); 
            
            $table->string('description')->comment('Descripción del item en el momento de la venta');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 10, 2)->comment('Precio unitario en el momento de la venta');
            $table->decimal('discount_amount', 10, 2)->default(0.00);
            $table->decimal('tax_amount', 10, 2)->default(0.00);
            $table->decimal('line_total', 10, 2);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions_items');
    }
};