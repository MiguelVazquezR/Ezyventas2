<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Consolidated subscription & referral tables:
     * subscription_versions, subscription_items, subscription_payments,
     * referral_settings, referral_codes, referral_usages,
     * referrer_bank_accounts, billing_settings + subscriptions extra columns.
     */
    public function up(): void
    {
        // ─── Subscriptions extras (onboarding, referrer, facturacion) ───
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('onboarding_completed_at')->nullable()->after('slug');
            $table->boolean('referrer_discount_active')->default(false)->after('onboarding_completed_at');
            $table->boolean('facturacion_habilitada')->default(false)->comment('Whether the subscription has opted into CFDI invoicing via SW Sapien');
            $table->decimal('referrer_ongoing_discount_pct', 5, 2)->nullable();
        });

        // ─── Subscription versions ───
        Schema::create('subscription_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->timestamps();
        });

        // ─── Subscription items ───
        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_version_id')->constrained()->cascadeOnDelete();
            $table->string('item_key')->comment('ej. base_plan, extra_users');
            $table->string('item_type')->comment('ej. module, user_limit');
            $table->string('name');
            $table->integer('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->string('billing_period')->default('mensual');
            $table->timestamps();
        });

        // ─── Subscription payments ───
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_version_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->decimal('referral_discount_pct', 5, 2)->nullable();
            $table->decimal('referral_discount_amount', 10, 2)->nullable();
            $table->string('invoice_status')->default('no_solicitada');
            $table->string('payment_method');
            $table->string('status')->default('approved');
            $table->json('payment_details')->nullable();
            $table->boolean('invoiced')->default(false);
            $table->timestamps();
        });

        // ─── Referral settings ───
        Schema::create('referral_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('referred_discount_pct', 5, 2)->default(15);
            $table->decimal('referrer_reward_pct', 5, 2)->default(50);
            $table->decimal('referrer_ongoing_discount_pct', 5, 2)->default(10);
            $table->timestamps();
        });

        // ─── Referral codes (user-based final schema) ───
        Schema::create('referral_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 12)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ─── Referral usages ───
        Schema::create('referral_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('referred_subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('subscription_payment_id')->constrained()->cascadeOnDelete();
            $table->enum('reward_status', ['pending', 'paid', 'cancelled'])->default('pending');
            $table->decimal('referred_discount_pct', 5, 2);
            $table->decimal('referrer_reward_pct', 5, 2);
            $table->decimal('referrer_ongoing_discount_pct', 5, 2);
            $table->decimal('monthly_base_amount', 10, 2);
            $table->decimal('reward_amount', 10, 2);
            $table->timestamp('reward_paid_at')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();
        });

        // ─── Referrer bank accounts ───
        Schema::create('referrer_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('clabe', 18);
            $table->string('bank_name');
            $table->string('account_holder_name');
            $table->timestamps();

            $table->unique('user_id');
        });

        // ─── Billing settings ───
        Schema::create('billing_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('emitter_rfc', 13);
            $table->string('emitter_legal_name');
            $table->string('emitter_tax_regime', 10);
            $table->string('emitter_postal_code', 5);
            $table->string('logo_path')->nullable()->comment('Ruta del logotipo del emisor');
            $table->text('api_key')->nullable();
            $table->timestamps();

            $table->unique('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_settings');
        Schema::dropIfExists('referrer_bank_accounts');
        Schema::dropIfExists('referral_usages');
        Schema::dropIfExists('referral_codes');
        Schema::dropIfExists('referral_settings');
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscription_versions');

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'onboarding_completed_at',
                'referrer_discount_active',
                'facturacion_habilitada',
                'referrer_ongoing_discount_pct',
            ]);
        });
    }
};