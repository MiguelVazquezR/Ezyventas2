<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MySQL TIMESTAMP columns only store dates up to 2038-01-19 03:14:07, so
     * an `end_date` of a subscription without expiry cannot be persisted there
     * (the free plan is created with `now()->addYears(50)`). DATETIME has no
     * such limit and keeps the value as written, without timezone conversion.
     */
    public function up(): void
    {
        Schema::table('subscription_versions', function (Blueprint $table) {
            $table->dateTime('start_date')->nullable()->change();
            $table->dateTime('end_date')->nullable()->change();
        });
    }

    /**
     * Rolling back is only safe while no version holds a date beyond 2038.
     */
    public function down(): void
    {
        Schema::table('subscription_versions', function (Blueprint $table) {
            $table->timestamp('start_date')->nullable()->change();
            $table->timestamp('end_date')->nullable()->change();
        });
    }
};
