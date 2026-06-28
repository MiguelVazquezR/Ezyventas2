<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add CSD certificate columns to fiscal_profiles.
     *
     * Stores the SAT-issued certificate number, validity period,
     * and local file paths for the .cer and .key files.
     */
    public function up(): void
    {
        Schema::table('fiscal_profiles', function (Blueprint $table) {
            $table->string('certificate_number', 20)->nullable()->after('sw_user_id')
                ->comment('Número de serie del certificado SAT (20 dígitos)');
            $table->timestamp('valid_from')->nullable()->after('certificate_number')
                ->comment('Fecha de inicio de vigencia del CSD');
            $table->timestamp('valid_to')->nullable()->after('valid_from')
                ->comment('Fecha de vencimiento del CSD');
            $table->string('cer_file_path')->nullable()->after('valid_to')
                ->comment('Ruta relativa al archivo .cer en storage');
            $table->string('key_file_path')->nullable()->after('cer_file_path')
                ->comment('Ruta relativa al archivo .key en storage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fiscal_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'certificate_number',
                'valid_from',
                'valid_to',
                'cer_file_path',
                'key_file_path',
            ]);
        });
    }
};
