<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('help_category_id')->constrained('help_categories')->onDelete('cascade');
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('type'); // article, video
            $table->longText('content')->nullable();
            $table->string('youtube_id')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->string('status')->default('draft'); // published
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_articles');
    }
};