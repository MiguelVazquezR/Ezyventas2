<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_variants', function (Blueprint $table) {
            $table->id();
            // Relación con el servicio base
            $table->foreignId('service_id')->constrained('services')->onDelete('cascade');
            
            // Nombre de la variante (Ej: "iPhone 12 - OLED", "150cc", "220V")
            $table->string('name');
            
            // El precio específico de esta variante
            $table->decimal('price', 10, 2)->default(0);
            
            // Opcional: Tiempo estimado para esta variante en particular
            $table->string('duration_estimate')->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_variants');
    }
};