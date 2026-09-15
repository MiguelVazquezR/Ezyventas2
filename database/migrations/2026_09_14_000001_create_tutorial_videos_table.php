<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutorial_videos', function (Blueprint $table) {
            $table->id();

            // Module key from config/tutorials.php (e.g. 'billing', 'pos') +
            // free-text section title used to group videos inside the module
            // (e.g. 'Pagos y complementos').
            $table->string('module', 50)->index();
            $table->string('section', 120);

            $table->string('title');
            $table->string('description')->nullable();
            $table->string('duration', 10)->nullable();

            // External link (YouTube/Vimeo) OR an uploaded file on the public
            // disk — at most one of them is set per video.
            $table->string('url', 500)->nullable();
            $table->string('file_path', 500)->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutorial_videos');
    }
};
