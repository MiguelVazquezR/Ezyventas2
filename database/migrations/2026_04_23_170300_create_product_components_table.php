<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_components', function (Blueprint $table) {
            $table->id();
            // El ID del producto Padre (El Combo/Kit)
            $table->foreignId('composite_product_id')->constrained('products')->cascadeOnDelete();
            
            // Relación polimórfica: Permite vincular tanto un 'Product' simple como un 'ProductAttribute' (variante)
            $table->morphs('componentable'); 
            
            // La cantidad de este componente que se requiere para armar 1 Kit
            $table->decimal('quantity', 10, 2)->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_components');
    }
};