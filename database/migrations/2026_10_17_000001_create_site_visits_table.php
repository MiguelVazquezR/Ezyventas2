<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_visits', function (Blueprint $table) {
            $table->id();

            // Public page that received the visit (e.g. '/', '/store/my-store').
            $table->string('path', 500);
            $table->string('route_name', 150)->nullable();

            // Anonymous fingerprint (IP + user agent) used to compute unique visitors.
            $table->string('visitor_hash', 64);
            $table->string('referrer', 500)->nullable();
            $table->string('device', 20)->nullable();

            $table->timestamps();

            $table->index('created_at');
            $table->index(['created_at', 'visitor_hash']);
            $table->index('path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_visits');
    }
};
