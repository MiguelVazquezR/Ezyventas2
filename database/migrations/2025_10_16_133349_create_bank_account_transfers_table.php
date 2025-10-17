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
        Schema::create('bank_account_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('folio')->nullable();
            $table->foreignId('subscription_id')->constrained()->onDelete('cascade');
            $table->foreignId('from_account_id')->constrained('bank_accounts')->onDelete('cascade');
            $table->foreignId('to_account_id')->constrained('bank_accounts')->onDelete('cascade');
            $table->decimal('amount', 15, 2);
            $table->text('notes')->nullable();
            $table->timestamp('transfer_date');
            $table->timestamps();

            // Asegura que el folio sea único por suscripción
            $table->unique(['subscription_id', 'folio']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_account_transfers');
    }
};