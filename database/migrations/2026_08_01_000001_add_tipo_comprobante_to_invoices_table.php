<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // CFDI 4.0 TipoDeComprobante (I, E, P, N, T)
            if (! Schema::hasColumn('invoices', 'tipo_comprobante')) {
                $table->string('tipo_comprobante', 5)
                    ->nullable()
                    ->default('I')
                    ->after('exportacion');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'tipo_comprobante')) {
                $table->dropColumn('tipo_comprobante');
            }
        });
    }
};
