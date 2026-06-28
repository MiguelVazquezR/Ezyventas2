<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add email and password columns required by SW Sapien PAC
     * for sub-user account provisioning.
     */
    public function up(): void
    {
        Schema::table('fiscal_profiles', function (Blueprint $table) {
            $table->string('email')->nullable()->after('regimen_fiscal')
                ->comment('Email de contacto para la subcuenta en SW Sapien');
            $table->string('password')->nullable()->after('email')
                ->comment('Contraseña autogenerada para la subcuenta del PAC');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fiscal_profiles', function (Blueprint $table) {
            $table->dropColumn(['email', 'password']);
        });
    }
};
