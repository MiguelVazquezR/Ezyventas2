<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add fiscal_profile_id to invoices so each CFDI is linked to the
     * specific RFC (emisor) that issued it, enabling multi-RFC billing
     * from a single subscription.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('fiscal_profile_id')->nullable()->after('branch_id');
            $table->index('fiscal_profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['fiscal_profile_id']);
            $table->dropColumn('fiscal_profile_id');
        });
    }
};
