<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Consolidated support & help tables:
     * help_categories, help_articles, support_tickets, ticket_responses,
     * custom_field_definitions.
     */
    public function up(): void
    {
        // ─── Help categories ───
        Schema::create('help_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('help_categories')->cascadeOnDelete();
            $table->timestamps();
        });

        // ─── Help articles ───
        Schema::create('help_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('help_category_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('type');
            $table->longText('content')->nullable();
            $table->string('youtube_id')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->string('status')->default('draft');
            $table->timestamps();
        });

        // ─── Support tickets ───
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ticket_number')->unique();
            $table->string('subject');
            $table->string('status');
            $table->string('category');
            $table->timestamps();
        });

        // ─── Ticket responses ───
        Schema::create('ticket_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('message');
            $table->boolean('is_internal_note')->default(false);
            $table->timestamps();
        });

        // ─── Custom field definitions ───
        Schema::create('custom_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('module')->comment('e.g., service_orders');
            $table->string('name')->comment('Label visible to the user, e.g., "PIN de Desbloqueo"');
            $table->string('key')->comment('Machine-readable key, e.g., "pin_desbloqueo"');
            $table->string('type')->comment('Input type: text, number, boolean, textarea');
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->timestamps();

            $table->unique(['subscription_id', 'module', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_definitions');
        Schema::dropIfExists('ticket_responses');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('help_articles');
        Schema::dropIfExists('help_categories');
    }
};