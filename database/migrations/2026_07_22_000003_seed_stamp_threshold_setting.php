<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('setting_definitions')->insert([
            'key'           => 'stamp_large_purchase_threshold',
            'name'          => 'Umbral de revisión de compras grandes',
            'description'   => 'Cantidad de timbres a partir de la cual una compra por Mercado Pago requiere revisión manual del superadmin antes de aplicarse al PAC.',
            'module'        => 'billing',
            'level'         => 'platform',
            'type'          => 'integer',
            'default_value' => '1000',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('setting_definitions')->where('key', 'stamp_large_purchase_threshold')->delete();
    }
};
