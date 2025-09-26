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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            
            // Un cliente se puede registrar en una sucursal "hogar".
            $table->foreignId('branch_id')->nullable()->constrained('branches')->onDelete('set null');

            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('phone')->nullable();
            $table->json('address')->nullable();
            $table->string('tax_id')->nullable()->comment('RFC o identificador fiscal');
            
            // Gestión de crédito
            $table->decimal('balance', 10, 2)->default(0.00)->comment('Saldo a favor o deuda del cliente');
            $table->decimal('credit_limit', 10, 2)->default(0.00);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};