<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stamp_global_stats_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('total_stamps_assigned')->default(0);
            $table->unsignedInteger('total_stamps_used')->default(0);
            $table->unsignedInteger('active_issuers_count')->default(0);
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stamp_global_stats_snapshots');
    }
};
