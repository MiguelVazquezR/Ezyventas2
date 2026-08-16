<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add requires_manual_review to invoices and the folio unique constraint.
     *
     * requires_manual_review activates when a stamp reservation reaches
     * 'manual_review', so the invoice shows up filterable in the admin panel.
     *
     * The unique(branch_id, series, folio) constraint protects against the
     * folio race condition at the DB level. It was verified that no duplicate
     * (branch_id, series, folio) rows exist before applying it.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('requires_manual_review')->default(false)->after('status');
            $table->unique(['branch_id', 'series', 'folio']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'series', 'folio']);
            $table->dropColumn('requires_manual_review');
        });
    }
};
