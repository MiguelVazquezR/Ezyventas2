<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allows a referral to be registered during the 30-day free trial, before
     * any payment exists, and adds the new lifecycle states:
     *   - trial:   referred during sign-up, still on the free trial (no payment yet)
     *   - expired: the free trial ended without a payment
     *
     * MySQL uses raw ALTER ... MODIFY statements; SQLite (used by the test
     * suite) does not support them, so the columns are rebuilt natively there.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->changeColumnsForSqlite(['pending', 'paid', 'cancelled', 'trial', 'expired'], nullable: true);

            return;
        }

        DB::statement('ALTER TABLE `referral_usages` MODIFY `subscription_payment_id` BIGINT UNSIGNED NULL');

        // Antes del primer pago no hay mensualidad ni premio calculados.
        DB::statement('ALTER TABLE `referral_usages` MODIFY `monthly_base_amount` DECIMAL(10, 2) NULL');
        DB::statement('ALTER TABLE `referral_usages` MODIFY `reward_amount` DECIMAL(10, 2) NULL');

        DB::statement("ALTER TABLE `referral_usages` MODIFY `reward_status` ENUM('pending','paid','cancelled','trial','expired') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->changeColumnsForSqlite(['pending', 'paid', 'cancelled'], nullable: false);

            return;
        }

        DB::statement("ALTER TABLE `referral_usages` MODIFY `reward_status` ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending'");

        DB::statement('ALTER TABLE `referral_usages` MODIFY `monthly_base_amount` DECIMAL(10, 2) NOT NULL');
        DB::statement('ALTER TABLE `referral_usages` MODIFY `reward_amount` DECIMAL(10, 2) NOT NULL');

        DB::statement('ALTER TABLE `referral_usages` MODIFY `subscription_payment_id` BIGINT UNSIGNED NOT NULL');
    }

    /**
     * @param  array<int, string>  $rewardStatuses
     */
    private function changeColumnsForSqlite(array $rewardStatuses, bool $nullable): void
    {
        Schema::table('referral_usages', function (Blueprint $table) use ($rewardStatuses, $nullable) {
            $table->unsignedBigInteger('subscription_payment_id')->nullable($nullable)->change();
            $table->decimal('monthly_base_amount', 10, 2)->nullable($nullable)->change();
            $table->decimal('reward_amount', 10, 2)->nullable($nullable)->change();
            $table->enum('reward_status', $rewardStatuses)->default('pending')->change();
        });
    }
};
