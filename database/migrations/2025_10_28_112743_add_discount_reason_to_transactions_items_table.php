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
        Schema::table('transactions_items', function (Blueprint $table) {
            // Añade la columna para guardar el motivo del descuento/aumento
            $table->string('discount_reason')->nullable()->after('discount_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions_items', function (Blueprint $table) {
            $table->dropColumn('discount_reason');
        });
    }
};