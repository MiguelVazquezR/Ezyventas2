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
        Schema::create('branch_service', function (Blueprint $table) {
            $table->id();
            
            // Relación con la sucursal
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            
            // Relación con el servicio
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            
            $table->timestamps();

            // Evitar duplicados de la misma relación
            $table->unique(['branch_id', 'service_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_service');
    }
};