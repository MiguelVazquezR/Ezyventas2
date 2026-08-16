<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_tool_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('tool_name');
            $table->json('arguments');
            $table->json('result')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tool_executions');
    }
};
