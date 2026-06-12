<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->decimal('referral_discount_pct', 5, 2)->nullable()->after('amount');
            $table->decimal('referral_discount_amount', 10, 2)->nullable()->after('referral_discount_pct');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropColumn('referral_discount_pct');
            $table->dropColumn('referral_discount_amount');
        });
    }
};
