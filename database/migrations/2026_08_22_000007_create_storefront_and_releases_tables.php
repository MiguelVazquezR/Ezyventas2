<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Consolidated storefront + release notes tables:
     * print_templates, branch_print_template, store_configs, orders,
     * order_items, order_status_logs, release_notes, release_note_user.
     */
    public function up(): void
    {
        // ─── Print templates ───
        Schema::create('print_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('name')->comment('Nombre descriptivo, ej: "Ticket de Venta Estándar 80mm"');
            $table->string('type')->default('ticket_venta');
            $table->string('context_type')->default('general');
            $table->json('content')->comment('Almacena la estructura de operaciones ESC/POS en formato JSON');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // ─── Branch ↔ print template pivot ───
        Schema::create('branch_print_template', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('print_template_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        // ─── Store configs (online store settings + Mercado Pago + notifications) ───
        Schema::create('store_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('slug')->comment('Unique URL identifier for the store');
            $table->boolean('is_active')->default(false)->comment('Whether the store is publicly visible');
            $table->string('store_name')->nullable();
            $table->string('tagline', 120)->nullable()->comment('Short store slogan shown below the name');
            $table->text('description')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('primary_color', 7)->nullable()->default('#3B82F6');
            $table->string('secondary_color', 7)->nullable()->default('#1D4ED8');
            $table->string('theme_mode', 5)->nullable()->default('light')->comment('Store theme: light or dark');
            $table->text('welcome_message')->nullable();
            $table->boolean('accepts_pickup')->default(true);
            $table->boolean('accepts_delivery')->default(true);
            $table->boolean('allow_out_of_stock_purchases')->default(false)->comment('Allow customers to buy out-of-stock products');
            $table->integer('out_of_stock_extra_minutes')->nullable()->comment('Extra preparation minutes when restocking is needed');
            $table->string('whatsapp_number', 20)->nullable()->comment('Store WhatsApp contact number');
            $table->decimal('delivery_fee', 10, 2)->nullable()->default(0);
            $table->decimal('free_shipping_minimum', 10, 2)->nullable()->default(0)->comment('Minimum order amount for free delivery. 0 means no free shipping threshold.');
            $table->integer('preparation_time_minutes')->nullable()->default(30);
            $table->text('delivery_policy')->nullable();
            $table->text('terms_policy')->nullable()->comment('Returns, terms and conditions policy');
            $table->text('footer_note')->nullable();
            $table->string('custom_domain')->nullable();
            $table->text('mp_access_token')->nullable()->comment('Encrypted Mercado Pago access token');
            $table->text('mp_refresh_token')->nullable()->comment('Encrypted Mercado Pago refresh token');
            $table->string('mp_user_id')->nullable()->comment('Mercado Pago account user ID');
            $table->string('mp_public_key')->nullable()->comment('Mercado Pago public key for frontend SDK');
            $table->timestamp('mp_token_expires_at')->nullable()->comment('When the MP access token expires');
            $table->boolean('payment_mp_enabled')->default(false)->comment('Whether Mercado Pago is enabled');
            $table->boolean('payment_cash_enabled')->default(true)->comment('Whether cash on delivery is enabled');
            $table->text('cash_instructions')->nullable()->comment('Custom instructions for cash payment');
            $table->boolean('notify_email_enabled')->default(false);
            $table->json('notification_emails')->nullable();
            $table->timestamps();

            $table->unique('subscription_id');
            $table->unique('slug');
            $table->unique('custom_domain');
        });

        // ─── Orders ───
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_config_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('order_number')->comment('Sequential order number per store');
            $table->string('status')->default('pending');
            $table->string('delivery_type')->comment('pickup or delivery');
            $table->string('customer_name');
            $table->string('customer_phone', 30);
            $table->string('customer_email')->nullable();
            $table->text('delivery_address')->nullable();
            $table->text('customer_notes')->nullable();
            $table->decimal('subtotal', 10, 2);
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            $table->string('payment_method')->nullable()->comment('mercadopago or cash');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        // ─── Order items ───
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->decimal('unit_price', 10, 2);
            $table->integer('quantity');
            $table->decimal('subtotal', 10, 2);
            $table->timestamps();
        });

        // ─── Order status logs ───
        Schema::create('order_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // ─── Release notes ───
        Schema::create('release_notes', function (Blueprint $table) {
            $table->id();
            $table->string('version')->nullable()->comment('Ej. v1.2.0');
            $table->string('title');
            $table->text('excerpt')->nullable()->comment('Breve descripción para listas');
            $table->longText('content')->comment('Contenido HTML textual (Media gestionada por Spatie MediaLibrary)');
            $table->boolean('is_published')->default(false);
            $table->boolean('is_banner')->default(false);
            $table->string('banner_title')->nullable()->comment('Optional override title for the banner overlay');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        // ─── Release note ↔ user pivot ───
        Schema::create('release_note_user', function (Blueprint $table) {
            $table->foreignId('release_note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->useCurrent();
            $table->timestamp('banner_dismissed_at')->nullable();

            $table->primary(['release_note_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_note_user');
        Schema::dropIfExists('release_notes');
        Schema::dropIfExists('order_status_logs');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('store_configs');
        Schema::dropIfExists('branch_print_template');
        Schema::dropIfExists('print_templates');
    }
};