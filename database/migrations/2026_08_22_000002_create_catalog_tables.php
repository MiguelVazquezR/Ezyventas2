<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Consolidated catalog tables: brands, categories, global_products, providers,
     * products, product_attributes, product_reviews, product_components, services,
     * service_variants, attribute_definitions, attribute_options, brand_business_type.
     */
    public function up(): void
    {
        // ─── Brands ───
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('subscription_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        // ─── Categories ───
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('product');
            $table->string('business_type')->nullable();
            $table->foreignId('subscription_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        // ─── Global products (marketplace) ───
        Schema::create('global_products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('sku')->unique()->comment('Stock Keeping Unit');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('business_type_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('selling_price', 10, 2)->comment('Precio de venta al público');
            $table->string('measure_unit')->nullable()->comment('ej. pz, kg, lt');
            $table->timestamps();
        });

        // ─── Providers ───
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->json('address')->nullable();
            $table->timestamps();
        });

        // ─── Products (final schema) ───
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('sku')->nullable()->comment('Stock Keeping Unit');
            $table->string('sat_product_code', 8)->nullable();
            $table->string('sat_unit_code', 10)->nullable();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('global_product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('selling_price', 10, 2)->comment('Precio de venta al público');
            $table->json('price_tiers')->nullable();
            $table->decimal('cost_price', 10, 2)->nullable()->comment('Lo que le cuesta al negocio');
            $table->string('currency', 3)->default('MXN');
            $table->string('measure_unit')->nullable()->comment('ej. pz, kg, lt');
            $table->boolean('show_in_pos')->default(true);
            $table->boolean('is_bulk')->default(false)->comment('Indica si el producto se vende fraccionado a granel');
            $table->boolean('show_online')->default(false);
            $table->decimal('online_price', 10, 2)->nullable()->comment('Precio especial para venta online');
            $table->string('slug')->nullable();
            $table->integer('delivery_days')->nullable();
            $table->json('tags')->nullable();
            $table->boolean('is_featured')->default(false)->comment('Producto destacado');
            $table->integer('store_sort_order')->default(0);
            $table->boolean('is_on_sale')->default(false)->comment('Producto en oferta');
            $table->decimal('sale_price', 10, 2)->nullable();
            $table->timestamp('sale_start_date')->nullable();
            $table->timestamp('sale_end_date')->nullable();
            $table->boolean('requires_shipping')->default(true);
            $table->decimal('weight', 8, 2)->nullable()->comment('en gramos');
            $table->decimal('length', 8, 2)->nullable()->comment('en cm');
            $table->decimal('width', 8, 2)->nullable()->comment('en cm');
            $table->decimal('height', 8, 2)->nullable()->comment('en cm');
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('purchase_count')->default(0);
            $table->timestamps();

            $table->index('branch_id', 'idx_products_branch');
        });

        // ─── Product attributes (variants) ───
        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->json('attributes');
            $table->decimal('selling_price_modifier', 10, 2)->default(0);
            $table->string('sku_suffix')->nullable();
            $table->foreignId('global_product_id')->nullable();
            $table->timestamps();
        });

        // ─── Product reviews ───
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->unsignedTinyInteger('rating');
            $table->string('title')->nullable();
            $table->text('comment');
            $table->timestamps();
        });

        // ─── Product components (kits) ───
        Schema::create('product_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('composite_product_id')->constrained('products')->cascadeOnDelete();
            $table->string('componentable_type');
            $table->unsignedBigInteger('componentable_id');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->timestamps();
            $table->index(['componentable_type', 'componentable_id']);
        });

        // ─── Services ───
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('sat_product_code', 8)->nullable();
            $table->string('sat_unit_code', 10)->nullable();
            $table->text('description')->nullable();
            $table->string('slug')->unique();
            $table->decimal('base_price', 10, 2);
            $table->string('duration_estimate')->nullable();
            $table->boolean('show_online')->default(false);
            $table->timestamps();
        });

        // ─── Service variants ───
        Schema::create('service_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('price', 10, 2)->default(0);
            $table->string('duration_estimate')->nullable();
            $table->timestamps();
        });

        // ─── Attribute definitions ───
        Schema::create('attribute_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('requires_image')->default(false);
            $table->timestamps();
        });

        // ─── Attribute options ───
        Schema::create('attribute_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_definition_id')->constrained()->cascadeOnDelete();
            $table->string('value');
            $table->timestamps();
        });

        // ─── Brand ↔ business type pivot ───
        Schema::create('brand_business_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_type_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_business_type');
        Schema::dropIfExists('attribute_options');
        Schema::dropIfExists('attribute_definitions');
        Schema::dropIfExists('service_variants');
        Schema::dropIfExists('services');
        Schema::dropIfExists('product_components');
        Schema::dropIfExists('product_reviews');
        Schema::dropIfExists('product_attributes');
        Schema::dropIfExists('products');
        Schema::dropIfExists('providers');
        Schema::dropIfExists('global_products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('brands');
    }
};