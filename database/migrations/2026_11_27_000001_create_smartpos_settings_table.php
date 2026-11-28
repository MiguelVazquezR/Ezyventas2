<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Modo Caja Inteligente" preferences of a business (subscription).
 *
 * Additive on purpose: no existing table is modified. One row per subscription
 * and the absence of a row means "everything off", which is the default of every
 * tenant. Phase 1 reads/writes the bulk scale columns only; the smart checkout
 * columns are created now so Phase 2 does not need another migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smartpos_settings', function (Blueprint $table) {
            $table->id();

            // The tenant of the preference (business level, not branch level).
            $table->foreignId('subscription_id')->unique()->constrained()->cascadeOnDelete();

            $table->boolean('smart_checkout_enabled')->default(false);
            $table->boolean('scale_enabled')->default(false);

            // Scale profiles (baud rate, framing, reading pattern), the active
            // profile and the stability thresholds (readings / tolerance in g).
            $table->json('scale_settings')->nullable();

            // Reserved for Phase 2 (cameras, thresholds, crop retention). The
            // column exists; no logic reads it yet.
            $table->json('smart_checkout_settings')->nullable();

            // How long the cropped images of a weighed/recognised product are kept.
            $table->unsignedSmallInteger('retention_days')->default(30);

            $table->boolean('save_crops')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smartpos_settings');
    }
};
