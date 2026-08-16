<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make pac_accounts able to host a shared "normal" account.
     *
     * A shared account is not tied to a single subscription: any fiscal
     * profile of any subscription can link to it and share its stamp pool.
     */
    public function up(): void
    {
        Schema::table('pac_accounts', function (Blueprint $table) {
            // Una cuenta compartida no pertenece a una sola suscripción.
            $table->foreignId('subscription_id')->nullable()->change();

            // Bandera: cuenta normal compartida por varios suscriptores
            // (pool común de timbres en el PAC).
            $table->boolean('is_shared')->default(false)->after('subscription_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pac_accounts', function (Blueprint $table) {
            $table->dropColumn('is_shared');
            $table->foreignId('subscription_id')->nullable(false)->change();
        });
    }
};
