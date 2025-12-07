<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Optimizar búsqueda de gastos por sucursal y fecha
        Schema::table('expenses', function (Blueprint $table) {
            $table->index(['branch_id', 'expense_date', 'status'], 'idx_expenses_report');
        });

        // Optimizar búsqueda de pagos por fecha
        Schema::table('payments', function (Blueprint $table) {
            $table->index('payment_date', 'idx_payments_date');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex('idx_expenses_report');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('idx_payments_date');
        });
    }
};