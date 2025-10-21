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
        Schema::table('service_orders', function (Blueprint $table) {
            // Se añade después del campo 'technician_diagnosis' para mantener un orden lógico
            $table->decimal('subtotal', 10, 2)->default(0)->after('technician_diagnosis');
            $table->string('discount_type')->nullable()->after('subtotal'); // Almacena 'fixed' o 'percentage'
            $table->decimal('discount_value', 10, 2)->nullable()->after('discount_type'); // El valor del descuento (ej: 50 o 10)
            $table->decimal('discount_amount', 10, 2)->default(0)->after('discount_value'); // El monto calculado del descuento
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropColumn(['subtotal', 'discount_type', 'discount_value', 'discount_amount']);
        });
    }
};