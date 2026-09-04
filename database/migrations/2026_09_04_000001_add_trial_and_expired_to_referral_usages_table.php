<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Allows a referral to be registered during the 30-day free trial, before
     * any payment exists, and adds the new lifecycle states:
     *   - trial:   referred during sign-up, still on the free trial (no payment yet)
     *   - expired: the free trial ended without a payment
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE `referral_usages` MODIFY `subscription_payment_id` BIGINT UNSIGNED NULL');

        // Antes del primer pago no hay mensualidad ni premio calculados.
        DB::statement('ALTER TABLE `referral_usages` MODIFY `monthly_base_amount` DECIMAL(10, 2) NULL');
        DB::statement('ALTER TABLE `referral_usages` MODIFY `reward_amount` DECIMAL(10, 2) NULL');

        DB::statement("ALTER TABLE `referral_usages` MODIFY `reward_status` ENUM('pending','paid','cancelled','trial','expired') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `referral_usages` MODIFY `reward_status` ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending'");

        DB::statement('ALTER TABLE `referral_usages` MODIFY `monthly_base_amount` DECIMAL(10, 2) NOT NULL');
        DB::statement('ALTER TABLE `referral_usages` MODIFY `reward_amount` DECIMAL(10, 2) NOT NULL');

        DB::statement('ALTER TABLE `referral_usages` MODIFY `subscription_payment_id` BIGINT UNSIGNED NOT NULL');
    }
};
