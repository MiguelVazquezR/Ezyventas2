<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stamp_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_profile_id')
                ->constrained('fiscal_profiles')
                ->cascadeOnDelete();
            $table->string('type');               // 'entry' | 'exit'
            $table->string('description');         // Human-readable in Spanish
            $table->integer('quantity');           // Always positive
            $table->integer('balance_after');      // Running balance after this movement
            $table->nullableMorphs('reference');   // Links to StampPurchase, Invoice, etc.
            $table->json('metadata')->nullable();  // Extra info (payment_method, status, amount_total, etc.)
            $table->timestamps();

            $table->index('fiscal_profile_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stamp_movements');
    }
};
