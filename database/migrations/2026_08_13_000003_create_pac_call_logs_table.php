<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PAC call logs — audit trail of every call to the PAC.
     *
     * WARNING: request_payload must NEVER include the PAC account password
     * nor the binary content of CSD/private key files — only safe metadata
     * (RFC, series, folio, customid, etc.). Sanitize before saving.
     */
    public function up(): void
    {
        Schema::create('pac_call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pac_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation'); // stamp | cancel | upload_csd | authenticate | balance
            $table->string('customid')->nullable();
            $table->json('request_payload')->nullable(); // SIN password/CSD binario, solo metadatos seguros
            $table->unsignedSmallInteger('response_status_code')->nullable();
            $table->json('response_body')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['fiscal_profile_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pac_call_logs');
    }
};
