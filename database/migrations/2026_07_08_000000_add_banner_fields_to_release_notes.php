<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add banner fields to release_notes
        Schema::table('release_notes', function (Blueprint $table) {
            $table->boolean('is_banner')->default(false)->after('is_published');
            $table->string('banner_title')->nullable()->after('is_banner')->comment('Optional override title for the banner overlay');
        });

        // Add banner_dismissed_at to the pivot table
        Schema::table('release_note_user', function (Blueprint $table) {
            $table->timestamp('banner_dismissed_at')->nullable()->after('read_at');
        });
    }

    public function down(): void
    {
        Schema::table('release_notes', function (Blueprint $table) {
            $table->dropColumn(['is_banner', 'banner_title']);
        });

        Schema::table('release_note_user', function (Blueprint $table) {
            $table->dropColumn('banner_dismissed_at');
        });
    }
};
