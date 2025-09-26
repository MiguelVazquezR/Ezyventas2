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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('folio')->unique();

            // Relaciones: se desvinculan si el registro padre se borra, para no perder el historial.
            $table->foreignId('customer_id')->nullable()->constrained('customers')->onDelete('set null');
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('cash_register_session_id')->nullable()->constrained('cash_register_sessions')->onDelete('set null');
            
            // Detalles de la transacción
            $table->string('status'); // completado, pendiente, cancelado, reembolsado
            $table->string('channel'); // pos, online_store
            $table->decimal('subtotal', 10, 2);
            $table->decimal('total_discount', 10, 2)->default(0.00);
            $table->decimal('total_tax', 10, 2)->default(0.00);
            $table->string('currency', 3)->default('MXN');
            $table->text('notes')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->boolean('invoiced')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};