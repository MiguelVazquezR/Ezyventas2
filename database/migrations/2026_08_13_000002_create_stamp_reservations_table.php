<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stamp reservations.
     *
     * A reservation is created BEFORE calling the PAC so that:
     *  - "normal" accounts: protects the shared-pool balance (two concurrent
     *    stampings cannot consume the same stamp).
     *  - both account types: provides idempotency via `customid` so a timeout
     *    can be retried with the same payload without duplicating or losing
     *    the stamping.
     *
     * Statuses: held | confirmed | released | ambiguous | manual_review
     */
    public function up(): void
    {
        Schema::create('stamp_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_profile_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('reference'); // normalmente Invoice
            $table->string('customid', 100)->unique(); // identificador enviado al PAC, nunca reutilizado
            $table->unsignedInteger('quantity')->default(1);
            $table->string('status'); // held | confirmed | released | ambiguous | manual_review
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->json('last_pac_response')->nullable(); // snapshot del último intento, para depuración
            $table->timestamps();

            $table->index(['fiscal_profile_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stamp_reservations');
    }
};
