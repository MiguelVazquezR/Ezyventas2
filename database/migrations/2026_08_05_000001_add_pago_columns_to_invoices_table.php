<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CFDI de Pago (Tipo P) — Complemento de Pago 2.0 data.
        // These fields only apply to comprobantes de tipo "P"; they are null
        // for Ingreso (I), Egreso (E) and Traslado (T).
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'pago_fecha')) {
                $table->timestamp('pago_fecha')->nullable()->after('tipo_comprobante');
            }
            if (! Schema::hasColumn('invoices', 'pago_forma')) {
                $table->string('pago_forma', 5)->nullable()->after('pago_fecha');
            }
            if (! Schema::hasColumn('invoices', 'pago_moneda')) {
                $table->string('pago_moneda', 5)->nullable()->default('MXN')->after('pago_forma');
            }
            if (! Schema::hasColumn('invoices', 'pago_monto')) {
                $table->decimal('pago_monto', 12, 2)->nullable()->after('pago_moneda');
            }
            if (! Schema::hasColumn('invoices', 'pago_documentos')) {
                $table->json('pago_documentos')->nullable()->after('pago_monto');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'pago_fecha',
                'pago_forma',
                'pago_moneda',
                'pago_monto',
                'pago_documentos',
            ]);
        });
    }
};
