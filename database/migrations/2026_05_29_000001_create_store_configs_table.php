<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->unique()->constrained('subscriptions')->cascadeOnDelete();
            $table->string('slug')->unique()->comment('Unique URL identifier for the store');
            $table->boolean('is_active')->default(false)->comment('Whether the store is publicly visible');
            $table->string('store_name')->nullable();
            $table->text('description')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('primary_color', 7)->nullable()->default('#3B82F6');
            $table->string('secondary_color', 7)->nullable()->default('#1D4ED8');
            $table->text('welcome_message')->nullable();
            $table->boolean('accepts_pickup')->default(true);
            $table->boolean('accepts_delivery')->default(true);
            $table->decimal('delivery_fee', 10, 2)->nullable()->default(0);
            $table->integer('preparation_time_minutes')->nullable()->default(30);
            $table->text('delivery_policy')->nullable();
            $table->text('footer_note')->nullable();
            $table->string('custom_domain')->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_configs');
    }
};
