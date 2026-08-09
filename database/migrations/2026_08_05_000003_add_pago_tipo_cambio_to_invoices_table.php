<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CFDI de Pago (Tipo P) — Complemento de Pago 2.0: TipoCambioP.
        // Required only when the payment currency (pago_moneda) is not MXN.
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'pago_tipo_cambio')) {
                $table->decimal('pago_tipo_cambio', 8, 6)->nullable()->after('pago_monto');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'pago_tipo_cambio')) {
                $table->dropColumn('pago_tipo_cambio');
            }
        });
    }
};
