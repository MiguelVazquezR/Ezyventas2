<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_type_id')->nullable()->constrained('business_types')->onDelete('set null');
            $table->string('business_name');
            $table->string('commercial_name');
            $table->string('status')->default('activo'); // 'activo', 'expirado', 'suspendido'
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('tax_id')->nullable(); // RFC o identificador fiscal
            $table->json('address')->nullable();
            $table->string('slug')->unique(); // Para URLs amigables
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};