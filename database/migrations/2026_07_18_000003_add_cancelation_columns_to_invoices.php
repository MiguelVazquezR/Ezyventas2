<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('cancelation_requires_acceptance')->default(false)->after('canceled_at');
            $table->string('cancelation_status')->nullable()->after('cancelation_requires_acceptance');
            $table->timestamp('cancelation_requested_at')->nullable()->after('cancelation_status');
            $table->timestamp('cancelation_last_checked_at')->nullable()->after('cancelation_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'cancelation_requires_acceptance',
                'cancelation_status',
                'cancelation_requested_at',
                'cancelation_last_checked_at',
            ]);
        });
    }
};
