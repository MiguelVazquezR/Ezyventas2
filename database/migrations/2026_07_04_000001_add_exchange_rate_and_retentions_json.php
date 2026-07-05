<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── invoices: exchange_rate for non-MXN currencies ──
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'exchange_rate')) {
                $table->decimal('exchange_rate', 10, 6)
                    ->nullable()
                    ->after('currency')
                    ->comment('TipoCambio — required by SAT Anexo 20 when Moneda ≠ MXN');
            }
        });

        // ── invoice_items: retentions JSON array for multi-retention support ──
        Schema::table('invoice_items', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_items', 'retentions')) {
                $table->json('retentions')
                    ->nullable()
                    ->after('retained_tax_amount')
                    ->comment('Array of {type, rate, amount} objects for CFDI Retenciones node');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'exchange_rate')) {
                $table->dropColumn('exchange_rate');
            }
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            if (Schema::hasColumn('invoice_items', 'retentions')) {
                $table->dropColumn('retentions');
            }
        });
    }
};
