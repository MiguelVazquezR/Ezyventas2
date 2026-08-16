<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add emisor postal code to fiscal_profiles so the "LugarExpedicion"
     * CFDI field is sourced from the fiscal profile instead of the
     * branch-level billing settings.
     */
    public function up(): void
    {
        Schema::table('fiscal_profiles', function (Blueprint $table) {
            $table->string('postal_code', 5)->nullable()->after('regimen_fiscal')->comment('Código postal de expedición para CFDI');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_profiles', function (Blueprint $table) {
            $table->dropColumn('postal_code');
        });
    }
};
