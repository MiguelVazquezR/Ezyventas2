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
        Schema::create('accept_reject_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('fiscal_profile_id')->nullable()->constrained('fiscal_profiles')->nullOnDelete();
            $table->string('rfc', 13);
            $table->uuid('uuid');
            // Aceptacion | Rechazo
            $table->string('action', 20);
            // success | error
            $table->string('status', 20)->default('success');
            $table->text('acuse')->nullable();
            $table->string('estatus_uuid', 20)->nullable();
            $table->string('respuesta', 20)->nullable();
            $table->string('message')->nullable();
            $table->text('message_detail')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accept_reject_responses');
    }
};
