<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stamp_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_profile_id')->constrained('fiscal_profiles')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users');
            $table->unsignedInteger('stamp_quantity');
            $table->decimal('unit_price', 10, 4);
            $table->decimal('amount_total', 10, 2);
            $table->foreignId('pricing_tier_id')->nullable()->constrained('stamp_pricing_tiers')->nullOnDelete();
            $table->string('payment_method'); // mercadopago, bank_transfer, manual_adjustment
            $table->string('status'); // pending, awaiting_review, approved, rejected, failed, stamps_applied
            $table->string('mp_payment_id')->nullable();
            $table->string('mp_preference_id')->nullable();
            $table->string('proof_file_path')->nullable();
            $table->timestamp('proof_uploaded_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->json('pac_stamps_response_raw')->nullable();
            $table->timestamp('stamps_applied_at')->nullable();
            $table->string('admin_note')->nullable();
            $table->string('adjustment_type')->nullable(); // add, remove — solo manual_adjustment
            $table->timestamps();

            $table->index('fiscal_profile_id');
            $table->index('status');
            $table->index('payment_method');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stamp_purchases');
    }
};
