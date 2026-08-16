<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('tax_regime', 10)->nullable()->after('tax_id')->comment('Regimen fiscal del SAT');
            $table->json('fiscal_address')->nullable()->after('address')->comment('Domicilio fiscal cuando difiere del principal');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['tax_regime', 'fiscal_address']);
        });
    }
};
