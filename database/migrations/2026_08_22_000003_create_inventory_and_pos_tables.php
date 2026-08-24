<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Consolidated branch inventory + POS + banking tables:
     * bank_accounts, branch_product, branch_product_attribute, branch_service,
     * cash_registers, cash_register_sessions, cash_register_session_user,
     * session_cash_movements, customers, customer_balance_movements,
     * transactions, transactions_items, payments, bank_account_branch,
     * bank_account_transfers, bank_account_user.
     */
    public function up(): void
    {
        // ─── Bank accounts ───
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('bank_name');
            $table->string('owner_name');
            $table->string('account_name');
            $table->string('account_number')->nullable();
            $table->string('card_number')->nullable();
            $table->string('clabe')->nullable();
            $table->decimal('balance', 15, 2)->default(0);
            $table->timestamps();
        });

        // ─── Branch ↔ product pivot (stock per branch) ───
        Schema::create('branch_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('current_stock', 10, 3)->default(0);
            $table->decimal('reserved_stock', 10, 3)->default(0);
            $table->decimal('min_stock', 10, 3)->nullable();
            $table->decimal('max_stock', 10, 3)->nullable();
            $table->string('location')->nullable()->comment('Estante/Pasillo en esta sucursal específica');
            $table->timestamps();
            $table->unique(['branch_id', 'product_id']);
        });

        // ─── Branch ↔ product attribute pivot (variant stock per branch) ───
        Schema::create('branch_product_attribute', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_attribute_id')->constrained()->cascadeOnDelete();
            $table->decimal('current_stock', 10, 3)->default(0);
            $table->decimal('reserved_stock', 10, 3)->default(0);
            $table->decimal('min_stock', 10, 3)->nullable();
            $table->decimal('max_stock', 10, 3)->nullable();
            $table->string('location')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'product_attribute_id']);
        });

        // ─── Branch ↔ service pivot ───
        Schema::create('branch_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['branch_id', 'service_id']);
        });

        // ─── Cash registers ───
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('in_use')->default(false);
            $table->timestamps();
        });

        // ─── Cash register sessions ───
        Schema::create('cash_register_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_register_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->string('status');
            $table->decimal('opening_cash_balance', 10, 2);
            $table->json('opening_bank_balances')->nullable();
            $table->decimal('closing_cash_balance', 10, 2)->nullable();
            $table->decimal('calculated_cash_total', 10, 2)->nullable();
            $table->decimal('cash_difference', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // ─── Session ↔ user pivot ───
        Schema::create('cash_register_session_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_register_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        // ─── Session cash movements ───
        Schema::create('session_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_register_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->decimal('amount', 10, 2);
            $table->string('description');
            $table->timestamps();
        });

        // ─── Customers ───
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('phone')->nullable();
            $table->json('address')->nullable();
            $table->json('fiscal_address')->nullable()->comment('Domicilio fiscal cuando difiere del principal');
            $table->string('tax_id')->nullable()->comment('RFC o identificador fiscal');
            $table->string('tax_regime', 10)->nullable()->comment('Regimen fiscal del SAT');
            $table->decimal('balance', 10, 2)->default(0)->comment('Saldo a favor o deuda del cliente');
            $table->decimal('credit_limit', 10, 2)->default(0);
            $table->timestamps();

            $table->index('branch_id', 'idx_customers_branch');
        });

        // ─── Transactions ───
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('folio');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->json('contact_info')->nullable();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_register_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('transactionable_type')->nullable();
            $table->unsignedBigInteger('transactionable_id')->nullable();
            $table->string('status');
            $table->string('delivery_status')->nullable()->comment('pending, in_transit, delivered, failed');
            $table->date('layaway_expiration_date')->nullable()->comment('Fecha límite para liquidar el apartado');
            $table->dateTime('delivery_date')->nullable()->comment('Fecha y hora pactada para la entrega');
            $table->text('shipping_address')->nullable()->comment('Dirección completa de entrega o coordenadas');
            $table->string('channel');
            $table->decimal('subtotal', 10, 2);
            $table->decimal('shipping_cost', 10, 2)->default(0);
            $table->decimal('total_discount', 10, 2)->default(0);
            $table->decimal('total_tax', 10, 2)->default(0);
            $table->string('currency', 3)->default('MXN');
            $table->text('notes')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->boolean('invoiced')->default(false);
            $table->timestamps();

            $table->index(['transactionable_type', 'transactionable_id']);
            $table->index(['transactionable_id', 'transactionable_type']);
            $table->index(['branch_id', 'created_at'], 'idx_transactions_branch_created');
            $table->index('status', 'idx_transactions_status');
        });

        // ─── Transactions items ───
        Schema::create('transactions_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->string('itemable_type')->nullable();
            $table->unsignedBigInteger('itemable_id')->nullable();
            $table->string('description')->comment('Descripción del item en el momento de la venta');
            $table->decimal('quantity', 10, 3);
            $table->decimal('unit_price', 10, 2)->comment('Precio unitario en el momento de la venta');
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->string('discount_reason')->nullable();
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('line_total', 10, 2);
            $table->timestamps();

            $table->index(['itemable_type', 'itemable_id']);
        });

        // ─── Customer balance movements ───
        Schema::create('customer_balance_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->decimal('amount', 10, 2);
            $table->decimal('balance_after', 10, 2);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // ─── Payments ───
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_register_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('payment_method');
            $table->string('status');
            $table->timestamp('payment_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('payment_date', 'idx_payments_date');
        });

        // ─── Bank account branch pivot ───
        Schema::create('bank_account_branch', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_favorite')->default(false);
            $table->timestamps();
        });

        // ─── Bank account transfers ───
        Schema::create('bank_account_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('folio')->nullable();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_account_id')->constrained('bank_accounts')->cascadeOnDelete();
            $table->foreignId('to_account_id')->constrained('bank_accounts')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->text('notes')->nullable();
            $table->timestamp('transfer_date');
            $table->timestamps();

            $table->unique(['subscription_id', 'folio']);
        });

        // ─── Bank account user pivot ───
        Schema::create('bank_account_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['user_id', 'bank_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_account_user');
        Schema::dropIfExists('bank_account_transfers');
        Schema::dropIfExists('bank_account_branch');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('transactions_items');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('customer_balance_movements');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('session_cash_movements');
        Schema::dropIfExists('cash_register_session_user');
        Schema::dropIfExists('cash_register_sessions');
        Schema::dropIfExists('cash_registers');
        Schema::dropIfExists('branch_service');
        Schema::dropIfExists('branch_product_attribute');
        Schema::dropIfExists('branch_product');
        Schema::dropIfExists('bank_accounts');
    }
};