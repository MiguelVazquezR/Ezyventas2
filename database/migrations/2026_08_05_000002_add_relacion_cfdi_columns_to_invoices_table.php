<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nota de crédito (Tipo E) — CFDI Relacionados (c_TipoRelacion + UUIDs).
        // These fields only apply to comprobantes de tipo "E".
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'tipo_relacion')) {
                $table->string('tipo_relacion', 5)->nullable()->after('pago_documentos');
            }
            if (! Schema::hasColumn('invoices', 'cfdi_relacionados')) {
                $table->json('cfdi_relacionados')->nullable()->after('tipo_relacion');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['tipo_relacion', 'cfdi_relacionados']);
        });
    }
};
