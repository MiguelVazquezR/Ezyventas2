<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the provider/model columns from ai_conversations.
 *
 * The ai-agent package migrations create these columns, but the production
 * schema dropped them (see original 2026_07_24_094647 migration). This runs
 * after the package migrations to reproduce the final schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropColumn(['provider', 'model']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->string('provider')->nullable()->after('title');
            $table->string('model')->nullable()->after('provider');
        });
    }
};