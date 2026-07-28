<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'fecha_timbrado')) {
                $table->timestamp('fecha_timbrado')->nullable()->after('issued_at');
            }
            if (! Schema::hasColumn('invoices', 'sello_cfdi')) {
                $table->text('sello_cfdi')->nullable()->after('fecha_timbrado');
            }
            if (! Schema::hasColumn('invoices', 'sello_sat')) {
                $table->text('sello_sat')->nullable()->after('sello_cfdi');
            }
            if (! Schema::hasColumn('invoices', 'no_certificado_sat')) {
                $table->string('no_certificado_sat')->nullable()->after('sello_sat');
            }
            if (! Schema::hasColumn('invoices', 'rfc_prov_certif')) {
                $table->string('rfc_prov_certif', 13)->nullable()->after('no_certificado_sat');
            }
            if (! Schema::hasColumn('invoices', 'cadena_original_sat')) {
                $table->text('cadena_original_sat')->nullable()->after('rfc_prov_certif');
            }
            if (! Schema::hasColumn('invoices', 'qr_code_base64')) {
                $table->longText('qr_code_base64')->nullable()->after('cadena_original_sat');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'fecha_timbrado',
                'sello_cfdi',
                'sello_sat',
                'no_certificado_sat',
                'rfc_prov_certif',
                'cadena_original_sat',
                'qr_code_base64',
            ]);
        });
    }
};
