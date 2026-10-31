<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_articles', function (Blueprint $table) {
            $table->id();

            // Module key from config/tutorials.php (e.g. 'pos', 'billing').
            $table->string('module', 50)->index();

            $table->string('title');
            $table->string('excerpt', 500)->nullable();

            // Rich text (HTML) written from the admin panel.
            $table->longText('content');

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true)->index();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_articles');
    }
};
