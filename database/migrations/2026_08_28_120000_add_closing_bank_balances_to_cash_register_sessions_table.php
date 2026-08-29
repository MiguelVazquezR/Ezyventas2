<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Almacena el snapshot de saldos bancarios finales calculados al momento
     * del corte, para que el histórico quede inmutable aunque después se
     * editen pagos, gastos o transferencias vinculados a la sesión.
     */
    public function up(): void
    {
        Schema::table('cash_register_sessions', function (Blueprint $table) {
            $table->json('closing_bank_balances')->nullable()->after('opening_bank_balances');
        });
    }

    public function down(): void
    {
        Schema::table('cash_register_sessions', function (Blueprint $table) {
            $table->dropColumn('closing_bank_balances');
        });
    }
};
