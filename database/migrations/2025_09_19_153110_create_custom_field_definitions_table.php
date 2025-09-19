<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->onDelete('cascade');
            $table->string('module')->comment('e.g., service_orders');
            $table->string('name')->comment('Label visible to the user, e.g., "PIN de Desbloqueo"');
            $table->string('key')->comment('Machine-readable key, e.g., "pin_desbloqueo"');
            $table->string('type')->comment('Input type: text, number, boolean, textarea');
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->timestamps();
            
            $table->unique(['subscription_id', 'module', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_definitions');
    }
};