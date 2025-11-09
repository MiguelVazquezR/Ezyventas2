<?php

namespace Database\Seeders;

use App\Enums\PlanItemType;
use App\Models\PlanItem;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PlanItemSeeder extends Seeder
{
    public function run(): void
    {
        PlanItem::query()->delete();

        // Módulos
        $modules = [
            ['key' => 'module_pos', 'name' => 'Punto de Venta', 'price' => 130, 'icon' => 'pi pi-shop'], //Activo al suscribirse
            ['key' => 'module_financial_reports', 'name' => 'Reportes financieros', 'price' => 25, 'icon' => 'pi pi-chart-bar'], //Activo al suscribirse
            ['key' => 'module_transactions', 'name' => 'Historial de Ventas', 'price' => 0, 'icon' => 'pi pi-history'], //Activo al suscribirse
            ['key' => 'module_products', 'name' => 'Productos', 'price' => 0, 'icon' => 'pi pi-barcode'], //Activo al suscribirse
            ['key' => 'module_expenses', 'name' => 'Gastos', 'price' => 0, 'icon' => 'pi pi-arrow-up-right'], //Activo al suscribirse
            ['key' => 'module_customers', 'name' => 'Clientes', 'price' => 30, 'icon' => 'pi pi-users'], //Activo al suscribirse
            ['key' => 'module_services', 'name' => 'Servicios', 'price' => 50, 'icon' => 'pi pi-wrench'], //Activo al suscribirse
            ['key' => 'module_quotes', 'name' => 'Cotizaciones', 'price' => 35, 'icon' => 'pi pi-file-check'], 
            ['key' => 'module_cash_registers', 'name' => 'Cajas', 'price' => 0, 'icon' => 'pi pi-dollar'], //Activo al suscribirse
            ['key' => 'module_settings', 'name' => 'Configuraciones', 'price' => 0, 'icon' => 'pi pi-cog'], //Activo al suscribirse
        ];

        foreach ($modules as $module) {
            PlanItem::create([
                'key' => $module['key'],
                'type' => PlanItemType::MODULE,
                'name' => $module['name'],
                'monthly_price' => $module['price'],
                'meta' => ['icon' => $module['icon']],
            ]);
        }

        // Límites
        $limits = [
            ['key' => 'limit_branches', 'name' => 'Sucursales', 'price' => 30, 'quantity' => 1], // 1 sucursal al suscribirse
            ['key' => 'limit_users', 'name' => 'Usuarios', 'price' => 7.5, 'quantity' => 1], // 1 usuario al suscribirse
            ['key' => 'limit_products', 'name' => 'Productos', 'price' => 1.5, 'quantity' => 50], // 500 al suscribirse
            ['key' => 'limit_cash_registers', 'name' => 'Cajas Registradoras', 'price' => 7.5, 'quantity' => 1], // 1 caja al suscribirse
            ['key' => 'limit_print_templates', 'name' => 'Plantillas personalizadas', 'price' => 3, 'quantity' => 1], // 2 plantillas al suscribirse
        ];

        foreach ($limits as $limit) {
            PlanItem::create([
                'key' => $limit['key'],
                'type' => PlanItemType::LIMIT,
                'name' => $limit['name'],
                'monthly_price' => $limit['price'],
                'meta' => ['quantity' => $limit['quantity']],
            ]);
        }
    }
}