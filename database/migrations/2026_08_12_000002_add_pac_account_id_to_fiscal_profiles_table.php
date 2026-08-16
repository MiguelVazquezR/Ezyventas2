<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link fiscal_profiles to pac_accounts.
     *
     * The legacy sw_user_id / sw_account_email / password columns on
     * fiscal_profiles are intentionally kept for now (marked as legacy)
     * and will be removed in a later migration, only after 100% of the
     * active profiles have pac_account_id populated.
     */
    public function up(): void
    {
        Schema::table('fiscal_profiles', function (Blueprint $table) {
            $table->foreignId('pac_account_id')
                ->nullable()
                ->after('subscription_id')
                ->constrained('pac_accounts')
                ->nullOnDelete();

            $table->index('pac_account_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fiscal_profiles', function (Blueprint $table) {
            $table->dropForeign(['pac_account_id']);
            $table->dropIndex(['pac_account_id']);
            $table->dropColumn('pac_account_id');
        });
    }
};
