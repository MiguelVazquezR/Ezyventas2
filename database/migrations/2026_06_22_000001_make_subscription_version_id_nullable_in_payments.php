<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permite que subscription_version_id sea nullable para pagos de Mercado Pago
     * que aún no tienen versión creada (se crea al momento de la aprobación).
     */
    public function up(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->foreignId('subscription_version_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->foreignId('subscription_version_id')->nullable(false)->change();
        });
    }
};
