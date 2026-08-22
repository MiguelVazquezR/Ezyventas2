<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Consolidated sales support tables:
     * promotions, promotion_rules, promotion_effects, promotion_transaction,
     * quotes, quote_items, service_orders, service_order_items,
     * expense_categories, expenses.
     */
    public function up(): void
    {
        // ─── Promotions ───
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscription_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type')->comment('ITEM_DISCOUNT, CART_DISCOUNT, etc.');
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('usage_limit')->nullable()->comment('Cuántas veces se puede usar en total');
            $table->integer('priority')->default(0)->comment('Prioridad de aplicación (menor número = mayor prioridad)');
            $table->boolean('is_exclusive')->default(false)->comment('Si es true, no se puede combinar con otras');
            $table->timestamps();
        });

        // ─── Promotion rules ───
        Schema::create('promotion_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->string('type')->comment('MINIMUM_CART_TOTAL, REQUIRES_PRODUCT, etc.');
            $table->string('value')->comment('El valor de la condición, ej: 500.00 o el ID de un producto');
            $table->string('itemable_type')->nullable();
            $table->unsignedBigInteger('itemable_id')->nullable();
            $table->timestamps();

            $table->index(['itemable_type', 'itemable_id']);
        });

        // ─── Promotion effects ───
        Schema::create('promotion_effects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->string('type')->comment('FIXED_DISCOUNT, PERCENTAGE_DISCOUNT, etc.');
            $table->decimal('value', 10, 2)->comment('El monto o porcentaje del descuento');
            $table->string('itemable_type')->nullable();
            $table->unsignedBigInteger('itemable_id')->nullable();
            $table->timestamps();

            $table->index(['itemable_type', 'itemable_id']);
        });

        // ─── Promotion ↔ transaction pivot ───
        Schema::create('promotion_transaction', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->decimal('discount_applied', 10, 2);
            $table->timestamps();
        });

        // ─── Quotes ───
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->string('folio')->unique();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_quote_id')->nullable()->constrained('quotes')->cascadeOnDelete();
            $table->integer('version_number')->default(1);
            $table->string('status');
            $table->date('expiry_date')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('total_discount', 10, 2)->default(0);
            $table->decimal('total_tax', 10, 2)->default(0);
            $table->string('tax_type')->nullable();
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->decimal('shipping_cost', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email')->nullable();
            $table->string('recipient_phone')->nullable();
            $table->json('shipping_address')->nullable();
            $table->text('notes')->nullable();
            $table->json('custom_fields')->nullable();
            $table->timestamps();
        });

        // ─── Quote items ───
        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->string('itemable_type');
            $table->unsignedBigInteger('itemable_id');
            $table->string('description');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('line_total', 10, 2);
            $table->json('variant_details')->nullable();
            $table->timestamps();

            $table->index(['itemable_type', 'itemable_id']);
        });

        // ─── Service orders ───
        Schema::create('service_orders', function (Blueprint $table) {
            $table->id();
            $table->string('folio')->nullable();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->json('customer_address')->nullable();
            $table->string('technician_name')->nullable();
            $table->string('technician_commission_type')->nullable();
            $table->decimal('technician_commission_value', 10, 2)->nullable();
            $table->string('status');
            $table->timestamp('received_at')->nullable();
            $table->timestamp('promised_at')->nullable();
            $table->string('item_description');
            $table->text('reported_problems');
            $table->text('technician_diagnosis')->nullable();
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 10, 2)->nullable();
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('final_total', 10, 2)->nullable();
            $table->json('custom_fields')->nullable();
            $table->timestamps();
        });

        // ─── Service order items ───
        Schema::create('service_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_order_id')->constrained()->cascadeOnDelete();
            $table->string('itemable_type')->nullable();
            $table->unsignedBigInteger('itemable_id')->nullable();
            $table->string('description');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('line_total', 10, 2);
            $table->timestamps();

            $table->index(['itemable_type', 'itemable_id']);
        });

        // ─── Expense categories ───
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // ─── Expenses ───
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('folio');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->date('expense_date');
            $table->string('status');
            $table->text('description')->nullable();
            $table->string('payment_method');
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('session_cash_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_external')->default(false);
            $table->timestamps();

            $table->index(['branch_id', 'expense_date', 'status'], 'idx_expenses_report');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('service_order_items');
        Schema::dropIfExists('service_orders');
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('promotion_transaction');
        Schema::dropIfExists('promotion_effects');
        Schema::dropIfExists('promotion_rules');
        Schema::dropIfExists('promotions');
    }
};