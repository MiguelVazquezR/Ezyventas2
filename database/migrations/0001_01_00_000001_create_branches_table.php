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
        Schema::create('branches', function (Blueprint $table) {
            $table->id();

            // Una sucursal pertenece a una suscripción.
            $table->foreignId('subscription_id')->constrained('subscriptions')->onDelete('cascade');

            $table->string('name');
            $table->boolean('is_main')->default(false);
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->json('address')->nullable();
            $table->string('timezone')->nullable()->default('America/Mexico_City');
            $table->json('operating_hours')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
