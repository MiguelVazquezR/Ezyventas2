<?php

namespace Database\Seeders;

use App\Models\SettingDefinition;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SettingDefinitionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Limpiar la tabla para evitar duplicados
        SettingDefinition::query()->delete();

        // Módulo: Punto de Venta
        SettingDefinition::create([
            'module' => 'Punto de Venta',
            'name' => 'Habilitar descuentos',
            'description' => 'Permite al cajero aplicar descuentos manuales a los productos en el punto de venta.',
            'key' => 'pos_enable_discounts',
            'type' => 'boolean',
            'default_value' => 'true',
        ]);
        SettingDefinition::create([
            'module' => 'Punto de Venta',
            'name' => 'Monto máximo en caja',
            'description' => 'Establece un límite de efectivo en la caja. El sistema notificará cuando se exceda este monto.',
            'key' => 'pos_max_cash_amount',
            'type' => 'number',
            'default_value' => '10000',
        ]);

        // Módulo: Órdenes de Servicio
        SettingDefinition::create([
            'module' => 'Órdenes de Servicio',
            'name' => 'Prefijo para el folio de órdenes',
            'description' => 'Establece el prefijo que aparecerá antes del número consecutivo en tus órdenes de servicio (ej. "OS-").',
            'key' => 'service_orders_folio_prefix',
            'type' => 'text',
            'default_value' => 'OS-',
        ]);

        // Módulo: Notificaciones
         SettingDefinition::create([
            'module' => 'Notificaciones',
            'name' => 'Notificar por email cada nueva venta',
            'description' => 'Se enviará un correo electrónico al administrador con el resumen de cada venta completada.',
            'key' => 'notifications_new_sale_email',
            'type' => 'boolean',
            'default_value' => 'false',
        ]);
    }
}