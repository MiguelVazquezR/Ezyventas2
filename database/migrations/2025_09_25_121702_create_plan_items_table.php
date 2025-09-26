<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Enums\PlanItemType;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('plan_items', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique()->comment('Identificador único para el sistema (ej. module_pos, limit_users)');
            $table->string('type')->default(PlanItemType::MODULE->value);
            $table->string('name')->comment('Nombre legible para el usuario (ej. Punto de Venta)');
            $table->text('description')->nullable();
            $table->decimal('monthly_price', 8, 2)->default(0.00);
            $table->boolean('is_active')->default(true)->comment('Define si este ítem se puede contratar');
            $table->json('meta')->nullable()->comment('Propiedades adicionales (ej. icono para módulos, cantidad para límites)');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_items');
    }
};