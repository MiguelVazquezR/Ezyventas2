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
        // Índices para la tabla TRANSACTIONS
        Schema::table('transactions', function (Blueprint $table) {
            // Optimiza: Transaction::where('branch_id', ...)->whereBetween('created_at', ...)
            // Este es el índice más crítico para tu Dashboard.
            $table->index(['branch_id', 'created_at'], 'idx_transactions_branch_created');
            
            // Optimiza: where('status', 'completado')
            $table->index('status', 'idx_transactions_status');
        });

        // Índices para la tabla PRODUCTS
        Schema::table('products', function (Blueprint $table) {
            // Optimiza los conteos de inventario por sucursal
            $table->index('branch_id', 'idx_products_branch');
        });

        // Índices para la tabla CUSTOMERS
        Schema::table('customers', function (Blueprint $table) {
            // Optimiza los listados de clientes y deuda por sucursal
            $table->index('branch_id', 'idx_customers_branch');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('idx_transactions_branch_created');
            $table->dropIndex('idx_transactions_status');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_branch');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('idx_customers_branch');
        });
    }
};