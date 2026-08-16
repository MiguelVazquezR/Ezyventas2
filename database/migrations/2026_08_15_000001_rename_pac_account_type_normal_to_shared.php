<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename the "normal" external PAC account type to "shared".
 *
 * The reseller (Conectia) account is now a shared account: multiple
 * subscribers' RFCs link to it and its stamps are managed locally
 * (wallet), never exposed to the subscriber.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL enforces enum values. Order matters in strict mode:
        // 1) add 'shared' (keeping 'normal') → 2) rename rows → 3) drop 'normal'.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE pac_accounts MODIFY account_type ENUM('subaccount', 'normal', 'shared') NOT NULL");
        }

        // Rename existing rows.
        DB::table('pac_accounts')
            ->where('account_type', 'normal')
            ->update(['account_type' => 'shared']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE pac_accounts MODIFY account_type ENUM('subaccount', 'shared') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE pac_accounts MODIFY account_type ENUM('subaccount', 'shared', 'normal') NOT NULL");
        }

        DB::table('pac_accounts')
            ->where('account_type', 'shared')
            ->update(['account_type' => 'normal']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE pac_accounts MODIFY account_type ENUM('subaccount', 'normal') NOT NULL");
        }
    }
};
