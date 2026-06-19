<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->unique()->constrained('branches')->onDelete('cascade');
            $table->string('emitter_rfc', 13);
            $table->string('emitter_legal_name');
            $table->string('emitter_tax_regime', 10);           // e.g. "626" — Régimen Simplificado de Confianza
            $table->string('emitter_postal_code', 5);
            $table->text('api_key')->nullable();                // Encrypted at model level; NovaCFDI key once we have it
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_settings');
    }
};
