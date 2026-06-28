<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Each subscription (tenant) may bill under multiple RFCs.
     * A FiscalProfile represents one RFC registered as a sub-user
     * in the SW Sapien PAC, enabling multi-RFC invoicing from a
     * single EzyVentas subscription.
     */
    public function up(): void
    {
        Schema::create('fiscal_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->onDelete('cascade');
            $table->string('rfc', 13)->comment('RFC del emisor para este perfil fiscal');
            $table->string('razon_social')->comment('Razón social o nombre fiscal');
            $table->string('regimen_fiscal', 10)->comment('Clave del régimen fiscal del SAT, e.g. "626"');
            $table->string('sw_user_id')->nullable()->comment('Identificador del sub-usuario en SW Sapien');
            $table->string('sw_account_email')->nullable()->comment('Correo asignado a la subcuenta en el PAC');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['subscription_id', 'rfc'], 'fiscal_profiles_subscription_rfc_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_profiles');
    }
};
