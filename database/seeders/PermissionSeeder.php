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
                'pos.edit_prices' => 'Editar precios en el carrito',
            ],
            'Historial de Ventas' => [
                'transactions.access' => 'Acceder al historial de ventas',
                'transactions.see_details' => 'Ver detalles de ventas',
                'transactions.refund' => 'Generar devoluciones',
                'transactions.cancel' => 'Cancelar ventas',
            ],
            'Productos' => [
                'products.access' => 'Ver listado de productos',
                'products.see_details' => 'Ver detalles de productos',
                'products.create' => 'Crear nuevos productos',
                'products.edit' => 'Editar productos existentes',
                'products.delete' => 'Eliminar productos',
                'products.manage_stock' => 'Ajustar inventario',
                'products.manage_promos' => 'Gestionar promociones a productos',
                'products.manage_global_products' => 'Agregar o remover productos de catálogo base',
                'products.see_cost_price' => 'Ver precio de compra de productos',
                'products.import_export' => 'Importar y exportar productos',
            ],
            'Gastos' => [
                'expenses.access' => 'Ver listado de gastos',
                'expenses.see_all' => 'Ver todos los gastos registrados (propios y de otros usuarios)',
                'expenses.see_details' => 'Ver detalles de gastos',
                'expenses.create' => 'Crear nuevos gastos',
                'expenses.edit' => 'Editar gastos existentes',
                'expenses.delete' => 'Eliminar gastos',
                'expenses.import_export' => 'Importar y exportar gastos',
                'expenses.change_status' => 'Cambiar status de gastos (pendiente, pagado)',
            ],
            'Clientes' => [
                'customers.access' => 'Ver listado de clientes',
                'customers.see_details' => 'Ver detalles de clientes',
                'customers.create' => 'Crear nuevos clientes',
                'customers.edit' => 'Editar clientes existentes',
                'customers.delete' => 'Eliminar clientes',
                'customers.store_sale' => 'Registrar venta desde perfil de cliente',
                'customers.import_export' => 'Importar y exportar clientes',
                'customers.see_financial_info' => 'Ver información financiera de clientes',
            ],
            'Servicios' => [
                'services.catalog.access' => 'Ver catálogo de servicios',
                'services.catalog.see_details' => 'Ver detalles de servicios de catálogo',
                'services.catalog.create' => 'Crear nuevos servicio en catálogo',
                'services.catalog.edit' => 'Editar servicio de catálogo',
                'services.catalog.delete' => 'Eliminar servicio de catálogo',
                'services.catalog.import_export' => 'Importar y exportar servicios de catálogo',
                'services.orders.access' => 'Ver órdenes de servicio',
                'services.orders.see_details' => 'Ver detalles de órdenes de servicio',
                'services.orders.create' => 'Crear nuevas órdenes de servicio',
                'services.orders.edit' => 'Editar órdenes de servicio',
                'services.orders.delete' => 'Eliminar órdenes de servicio',
                'services.orders.import_export' => 'Importar y exportar órdenes de servicio',
                'services.orders.change_status' => 'Cambiar status y cancelar órdenes de servicio',
                'services.orders.see_customer_info' => 'Ver información de cliente en órdenes de servicio',
                'services.orders.see_financial_info' => 'Ver información financiera en órdenes de servicio',
                'services.orders.manage_custom_fields' => 'Administrar campos personalizados de órdenes de servicio',
                'services.print_tickets' => 'Imprimir tickets de órdenes',
                'services.print_etiquetas' => 'Imprimir etiqueta de órdenes',
            ],
            'Cotizaciones' => [
                'quotes.access' => 'Ver listado de cotizaciones',
                'quotes.see_details' => 'Ver detalles de cotizaciones',
                'quotes.create' => 'Crear nuevos cotizaciones',
                'quotes.edit' => 'Editar cotizaciones existentes',
                'quotes.delete' => 'Eliminar cotizaciones',
                'quotes.export' => 'Exportar cotizaciones',
                'quotes.change_status' => 'Cambiar status de cotizaciones',
                'quotes.create_sale' => 'Cambiar status de cotizaciones',
            ],
            'Reportes financieros' => [
                'financial_reports.access' => 'Acceder a reportes financieros',
            ],
            'Cajas' => [
                'cash_registers.access' => 'Ver listado de cajas registradoras',
                'cash_registers.manage' => 'Administrar cajas registradoras',
                'cash_registers.sessions.access' => 'Ver historial de cortes de caja (sesiones)',
                'cash_registers.sessions.create_movements' => 'Crear movimientos de efectivo en sesiones de caja',
            ],
            'Configuraciones' => [
                'settings.generals.access' => 'Acceder a las configuraciones generales',
                'settings.generals.update' => 'Modificar la configuración',
                'settings.roles_permissions.access' => 'Acceder a las configuraciones de roles y permisos',
                'settings.roles_permissions.manage' => 'Crear roles, asignar y remover permisos',
                'settings.roles_permissions.delete' => 'Eliminar roles',
                'settings.users.access' => 'Ver listado de usuarios registrados en la sucursal',
                'settings.users.create' => 'Crear usuarios',
                'settings.users.edit' => 'Edit usuarios',
                'settings.users.delete' => 'Eliminar usuarios',
                'settings.users.change_status' => 'Activar y desactivar usuarios. Un usuario desactivado ya no tiene acceso al sistema',
                'settings.templates.access' => 'Acceder al listado de plantillas.',
                'settings.templates.create' => 'Crear plantillas.',
                'settings.templates.edit' => 'Editar plantillas.',
                'settings.templates.delete' => 'Eliminar plantillas.',
            ],
            'Sistema' => [
                'system.branches.switch' => 'Cambiar entre sucursales',
                'system.bank_accounts.manage' => 'Ver y editar saldos de cuentas bancarias al abrir caja',
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