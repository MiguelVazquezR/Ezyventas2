<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Se borran los permisos existentes para evitar duplicados al re-ejecutar el seeder
        Permission::query()->delete();

        // Definición de Permisos por Módulo
        $permissionsByModule = [
            'Punto de Venta' => [
                'pos.access' => 'Acceder al Punto de Venta',
                'pos.create_sale' => 'Crear nuevas ventas',
                'pos.apply_discounts' => 'Aplicar descuentos en el carrito',
                'pos.edit_prices' => 'Editar precios en el carrito',
                'pos.use_customer_credit' => 'Realizar ventas a crédito',
            ],
            'Historial de Ventas' => [
                'transactions.access' => 'Acceder al historial de ventas',
                'transactions.edit' => 'Cambiar status de transacciones o ventas',
            ],
            'Productos' => [
                'products.access' => 'Ver listado de productos',
                'products.create' => 'Crear nuevos productos',
                'products.edit' => 'Editar productos existentes',
                'products.delete' => 'Eliminar productos',
                'products.manage_stock' => 'Ajustar inventario',
            ],
            'Gastos' => [
                'expenses.access' => 'Ver listado de gastos',
                'expenses.create' => 'Crear nuevos gastos',
                'expenses.edit' => 'Editar gastos existentes',
                'expenses.delete' => 'Eliminar gastos',
            ],
            'Clientes' => [
                'customers.access' => 'Ver listado de clientes',
                'customers.create' => 'Crear nuevos clientes',
                'customers.edit' => 'Editar clientes existentes',
                'customers.delete' => 'Eliminar clientes',
            ],
            'Servicios' => [
                'services.access_catalog' => 'Ver catálogo de servicios',
                'services.create_catalog' => 'Crear nuevos servicio en catálogo',
                'services.edit_catalog' => 'Editar servicio de catálogo',
                'services.delete_catalog' => 'Eliminar servicio de catálogo',
                'services.access_order' => 'Ver órdenes de servicio',
                'services.create_order' => 'Crear nuevas órdenes de servicio',
                'services.edit_order' => 'Editar órdenes de servicio',
                'services.delete_order' => 'Eliminar órdenes de servicio',
                'services.print_tickets' => 'Imprimir tickets de órdenes',
                'services.print_etiquetas' => 'Imprimir etiqueta de órdenes',
            ],
            'Cotizaciones' => [
                'quotes.access' => 'Ver listado de cotizaciones',
                'quotes.create' => 'Crear nuevos cotizaciones',
                'quotes.edit' => 'Editar cotizaciones existentes',
                'quotes.delete' => 'Eliminar cotizaciones',
            ],
            'Control Financiero' => [
                'financials.access_dashboard' => 'Ver dashboard financiero',
                'financials.manage_cash_registers' => 'Administrar cajas registradoras',
                'financials.manage_cash_sessions' => 'Abrir y cerrar sesiones de caja',
                'financials.view_sessions_history' => 'Ver historial de cortes de caja',
                'financials.manage_expenses' => 'Registrar y gestionar gastos',
            ],
            'Configuraciones' => [
                'settings.access' => 'Acceder a la configuración de la sucursal',
                'settings.update' => 'Modificar la configuración',
            ],
        ];

        // Creación de los permisos
        foreach ($permissionsByModule as $module => $permissions) {
            foreach ($permissions as $permission => $description) {
                Permission::create([
                    'name' => $permission,
                    'description' => $description,
                    'module' => $module,
                    'guard_name' => 'web',
                ]);
            }
        }
    }
}