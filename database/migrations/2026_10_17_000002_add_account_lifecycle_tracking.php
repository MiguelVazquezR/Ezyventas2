<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columns required by the automatic hibernation of inactive accounts:
     * the last sign-in of every user plus the two lifecycle markers of the
     * subscription (warning sent, data already purged).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('email_verified_at');
            $table->index('last_login_at');
        });

        // Existing accounts must not look inactive right after the deploy, so
        // the last known change of each user is used as the initial value.
        DB::table('users')
            ->whereNull('last_login_at')
            ->update(['last_login_at' => DB::raw('updated_at')]);

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('inactivity_warned_at')->nullable();
            $table->timestamp('data_purged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['inactivity_warned_at', 'data_purged_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_login_at']);
            $table->dropColumn('last_login_at');
        });
    }
};
