<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stamp_purchases', function (Blueprint $table) {
            $table->string('review_reason')->nullable()->after('status');
            $table->index('review_reason');
        });
    }

    public function down(): void
    {
        Schema::table('stamp_purchases', function (Blueprint $table) {
            $table->dropIndex(['review_reason']);
            $table->dropColumn('review_reason');
        });
    }
};
