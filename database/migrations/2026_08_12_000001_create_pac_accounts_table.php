<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the pac_accounts table.
     *
     * A PacAccount represents a login account in the PAC (SW Sapien),
     * either a dealer subaccount we provision ourselves, or an external
     * "normal" account managed by the reseller (Conectia).
     *
     * This is the entity that used to live implicitly inside
     * fiscal_profiles (sw_user_id / sw_account_email / password).
     * A single account can host multiple RFCs (fiscal profiles).
     */
    public function up(): void
    {
        Schema::create('pac_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('sw_sapien'); // por si en el futuro hay más de un PAC
            $table->enum('account_type', ['subaccount', 'normal']);
            $table->string('sw_user_id')->nullable()->comment('idUser que regresa el PAC, si lo tenemos');
            $table->string('login_email')->nullable()->comment('Credencial de login (subaccount: la generamos; normal: la da Conectia)');
            $table->string('password')->nullable()->comment('Cifrada, igual que fiscal_profiles.password');
            $table->enum('status', ['pending_request', 'pending_activation', 'active', 'inactive'])
                ->default('pending_request');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->text('admin_notes')->nullable()->comment('Bitácora de coordinación con el revendedor');
            $table->timestamps();

            $table->index('subscription_id');
            $table->index(['status', 'account_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pac_accounts');
    }
};
