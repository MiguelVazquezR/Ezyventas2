<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── invoices: exportacion, retained_taxes_total, and missing fiscal_profile_id FK ──
        Schema::table('invoices', function (Blueprint $table) {
            // Fiscal profile FK (referenced by model but missing from original migration)
            if (! Schema::hasColumn('invoices', 'fiscal_profile_id')) {
                $table->foreignId('fiscal_profile_id')
                    ->nullable()
                    ->after('branch_id')
                    ->constrained('fiscal_profiles')
                    ->onDelete('restrict');
            }

            // CFDI 4.0 Exportacion (01, 02, 03, 04)
            if (! Schema::hasColumn('invoices', 'exportacion')) {
                $table->string('exportacion', 5)
                    ->nullable()
                    ->default('01')
                    ->after('cfdi_use');
            }

            // Accumulated retained taxes (ISR + IVA retenido)
            if (! Schema::hasColumn('invoices', 'retained_taxes_total')) {
                $table->decimal('retained_taxes_total', 12, 2)
                    ->default(0)
                    ->after('taxes_total');
            }
        });

        // ── invoice_items: objeto_imp, retentions ──
        Schema::table('invoice_items', function (Blueprint $table) {
            // ObjetoImp — per SAT catalog (01, 02, 03)
            if (! Schema::hasColumn('invoice_items', 'objeto_imp')) {
                $table->string('objeto_imp', 5)
                    ->nullable()
                    ->default('02')
                    ->after('sat_product_code');
            }

            // Retained tax type (001 = ISR, 002 = IVA)
            if (! Schema::hasColumn('invoice_items', 'retained_tax_type')) {
                $table->string('retained_tax_type', 5)
                    ->nullable()
                    ->after('tax_rate');
            }

            // Retained tax rate (e.g. 0.0125 for 1.25% ISR)
            if (! Schema::hasColumn('invoice_items', 'retained_tax_rate')) {
                $table->decimal('retained_tax_rate', 6, 4)
                    ->nullable()
                    ->after('retained_tax_type');
            }

            // Retained tax amount
            if (! Schema::hasColumn('invoice_items', 'retained_tax_amount')) {
                $table->decimal('retained_tax_amount', 12, 2)
                    ->default(0)
                    ->after('retained_tax_rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['fiscal_profile_id']);
            $table->dropColumn(['fiscal_profile_id', 'exportacion', 'retained_taxes_total']);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['objeto_imp', 'retained_tax_type', 'retained_tax_rate', 'retained_tax_amount']);
        });
    }
};
