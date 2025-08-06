<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setting_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setting_definition_id')->constrained('setting_definitions')->onDelete('cascade');
            $table->morphs('settable'); // branch_id o user_id
            $table->text('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_values');
    }
};