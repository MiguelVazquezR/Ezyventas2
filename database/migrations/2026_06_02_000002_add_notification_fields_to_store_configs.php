<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_configs', function (Blueprint $table) {
            $table->boolean('notify_email_enabled')->default(false)->after('cash_instructions');
            $table->json('notification_emails')->nullable()->after('notify_email_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('store_configs', function (Blueprint $table) {
            $table->dropColumn(['notify_email_enabled', 'notification_emails']);
        });
    }
};
