<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('referrer_discount_active')->default(false)->after('onboarding_completed_at');
            $table->decimal('referrer_ongoing_discount_pct', 5, 2)->nullable()->after('referrer_discount_active');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('referrer_discount_active');
            $table->dropColumn('referrer_ongoing_discount_pct');
        });
    }
};
