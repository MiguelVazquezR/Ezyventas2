<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Información de contacto temporal (para pedidos de WhatsApp sin registrar cliente completo)
            // Ejemplo: {"name": "Juan Pérez", "phone": "555-1234"}
            $table->json('contact_info')->nullable()->after('customer_id');

            // Logística de entrega
            $table->dateTime('delivery_date')->nullable()->after('layaway_expiration_date')
                ->comment('Fecha y hora pactada para la entrega');
            
            $table->text('shipping_address')->nullable()->after('delivery_date')
                ->comment('Dirección completa de entrega o coordenadas');

            $table->string('delivery_status')->nullable()->after('status')
                ->comment('pending, in_transit, delivered, failed');

            // Costos
            $table->decimal('shipping_cost', 10, 2)->default(0.00)->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'contact_info',
                'delivery_date',
                'shipping_address',
                'delivery_status',
                'shipping_cost'
            ]);
        });
    }
};