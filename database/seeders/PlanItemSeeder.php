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
            ['key' => 'module_pos', 'name' => 'Punto de Venta', 'price' => 250, 'icon' => 'pi pi-shopping-cart'],
            ['key' => 'module_transactions', 'name' => 'Historial de Ventas', 'price' => 100, 'icon' => 'pi pi-history'],
            ['key' => 'module_products', 'name' => 'Productos', 'price' => 150, 'icon' => 'pi pi-box'],
            ['key' => 'module_expenses', 'name' => 'Gastos', 'price' => 100, 'icon' => 'pi pi-arrow-up-right'],
            ['key' => 'module_customers', 'name' => 'Clientes', 'price' => 150, 'icon' => 'pi pi-users'],
            ['key' => 'module_services', 'name' => 'Servicios', 'price' => 200, 'icon' => 'pi pi-wrench'],
            ['key' => 'module_quotes', 'name' => 'Cotizaciones', 'price' => 150, 'icon' => 'pi pi-file-edit'],
            ['key' => 'module_financials', 'name' => 'Control Financiero', 'price' => 250, 'icon' => 'pi pi-chart-line'],
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
            ['key' => 'limit_branches', 'name' => 'Sucursales', 'price' => 300, 'quantity' => 1],
            ['key' => 'limit_users', 'name' => 'Usuarios', 'price' => 50, 'quantity' => 1],
            ['key' => 'limit_products', 'name' => 'Productos', 'price' => 100, 'quantity' => 50],
            ['key' => 'limit_cash_registers', 'name' => 'Cajas Registradoras', 'price' => 75, 'quantity' => 1],
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