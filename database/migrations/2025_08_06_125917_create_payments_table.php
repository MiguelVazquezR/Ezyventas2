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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('transactions')->onDelete('cascade');
            $table->foreignId('cash_register_session_id')
                ->nullable() // Nulable para pagos que no son del POS (ej. online)
                ->constrained('cash_register_sessions')
                ->onDelete('set null');
            $table->decimal('amount', 10, 2);
            $table->string('payment_method'); // efectivo, tarjeta, transferencia, saldo de cliente
            $table->string('status'); // completado, fallido
            $table->timestamp('payment_date');
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
