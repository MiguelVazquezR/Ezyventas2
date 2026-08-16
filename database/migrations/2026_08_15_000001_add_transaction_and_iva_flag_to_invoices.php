<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the optional 1:1 link between an invoice and a POS sale
     * (transactions) plus the "prices include IVA" flag used by the
     * invoice builder to derive the SAT base from gross prices.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('transaction_id')->nullable()->after('customer_id')
                ->constrained('transactions')->nullOnDelete();
            $table->boolean('prices_include_iva')->default(false)->after('transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transaction_id');
            $table->dropColumn('prices_include_iva');
        });
    }
};
