<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── invoice_items: unit_name, no_identificacion, retained_tax_rate precision ──
        Schema::table('invoice_items', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_items', 'unit_name')) {
                $table->string('unit_name', 50)
                    ->nullable()
                    ->after('sat_unit_code')
                    ->comment('Commercial unit name, e.g. "Pieza", "Servicio"');
            }

            if (! Schema::hasColumn('invoice_items', 'no_identificacion')) {
                $table->string('no_identificacion', 100)
                    ->nullable()
                    ->after('sat_product_code')
                    ->comment('SKU or internal product identifier');
            }

            // Bump retained_tax_rate precision to 6,6 per PAC requirements
            $table->decimal('retained_tax_rate', 6, 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['unit_name', 'no_identificacion']);
            $table->decimal('retained_tax_rate', 6, 4)->nullable()->change();
        });
    }
};
