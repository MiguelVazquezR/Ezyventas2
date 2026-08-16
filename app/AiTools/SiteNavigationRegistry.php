<?php

namespace App\AiTools;

use Illuminate\Contracts\Auth\Authenticatable;

class SiteNavigationRegistry
{
    /**
     * Keep this in sync with resources/js/Layouts/AppMenu.vue manually — there is no
     * automatic sync between the two today. Consider extracting a shared JSON source
     * later if this drifts often.
     */
    private array $pages = [
        ['label' => 'Punto de venta',           'route' => 'pos.index',                      'permission' => 'pos.access',                      'keywords' => ['vender', 'venta', 'caja rápida']],
        ['label' => 'Reporte financiero',       'route' => 'financial-control.index',        'permission' => 'financial_reports.access',         'keywords' => ['finanzas', 'kpis', 'reporte financiero']],
        ['label' => 'Historial de ventas',      'route' => 'transactions.index',             'permission' => 'transactions.access',              'keywords' => ['ventas pasadas', 'transacciones', 'historial']],
        ['label' => 'Productos',                'route' => 'products.index',                  'permission' => 'products.access',                   'keywords' => ['inventario', 'catálogo', 'productos']],
        ['label' => 'Gastos',                   'route' => 'expenses.index',                  'permission' => 'expenses.access',                   'keywords' => ['gastos', 'registrar pago', 'egresos']],
        ['label' => 'Clientes',                 'route' => 'customers.index',                 'permission' => 'customers.access',                  'keywords' => ['clientes', 'compradores']],
        ['label' => 'Facturación',              'route' => 'invoices.index',                  'permission' => 'invoices.access',                   'keywords' => ['facturas', 'cfdi', 'facturación']],
        ['label' => 'Catálogo de servicios',    'route' => 'services.index',                  'permission' => 'services.catalog.access',           'keywords' => ['servicios', 'catálogo servicios']],
        ['label' => 'Órdenes de servicio',      'route' => 'service-orders.index',           'permission' => 'services.orders.access',            'keywords' => ['servicios', 'reparación', 'orden']],
        ['label' => 'Cotizaciones',             'route' => 'quotes.index',                    'permission' => 'quotes.access',                     'keywords' => ['cotización', 'presupuesto']],
        ['label' => 'Cajas registradoras',      'route' => 'cash-registers.index',           'permission' => 'cash_registers.access',             'keywords' => ['caja', 'corte de caja', 'cajas']],
        ['label' => 'Historial de cortes',      'route' => 'cash-register-sessions.index',   'permission' => 'cash_registers.sessions.access',    'keywords' => ['corte', 'cierre de caja', 'sesiones']],
        ['label' => 'Usuarios',                 'route' => 'users.index',                     'permission' => 'settings.users.access',             'keywords' => ['empleados', 'usuarios', 'permisos']],
        ['label' => 'Roles y permisos',         'route' => 'roles.index',                     'permission' => 'settings.roles_permissions.access', 'keywords' => ['roles', 'permisos']],
        ['label' => 'Mi suscripción',           'route' => 'subscription.manage',             'permission' => null,                                'keywords' => ['plan', 'suscripción', 'límite', 'pago', 'renovar']],
        ['label' => 'Configuraciones generales','route' => 'settings.index',                  'permission' => 'settings.generals.access',          'keywords' => ['configuración', 'ajustes', 'generales']],
        ['label' => 'Tienda en línea',          'route' => 'online-store.config',             'permission' => 'online_store.config.access',        'keywords' => ['tienda en línea', 'online', 'ecommerce']],
        ['label' => 'Pedidos en línea',         'route' => 'online-store.orders.index',       'permission' => 'online_store.orders.access',        'keywords' => ['pedidos online', 'órdenes en línea']],
        ['label' => 'Referidos',                'route' => 'referrals.index',                 'permission' => null,                                'keywords' => ['referidos', 'referir', 'recompensa']],
        ['label' => 'Plantillas personalizadas','route' => 'print-templates.index',           'permission' => 'settings.templates.access',         'keywords' => ['plantillas', 'impresión', 'tickets']],
        ['label' => 'Proveedores',              'route' => 'providers.index',                 'permission' => 'providers.access',                  'keywords' => ['proveedores', 'compras']],
        ['label' => 'Cuentas bancarias',        'route' => 'bank-accounts.index',             'permission' => 'bank_accounts.access',              'keywords' => ['bancos', 'cuentas', 'transferencias']],
    ];

    /**
     * Search for navigable pages matching the user's query, filtered by the user's permissions.
     *
     * @return array<int, array{label: string, url: string}>
     */
    public function searchFor(Authenticatable $user, string $query): array
    {
        $words = array_filter(explode(' ', mb_strtolower($query)), fn ($w) => mb_strlen($w) > 2);

        return collect($this->pages)
            ->filter(fn ($p) => $p['permission'] === null || $user->can($p['permission']))
            ->filter(fn ($p) => $this->matches($p, $words))
            ->map(fn ($p) => ['label' => $p['label'], 'url' => route($p['route'])])
            ->values()
            ->all();
    }

    private function matches(array $page, array $words): bool
    {
        $haystack = mb_strtolower($page['label'] . ' ' . implode(' ', $page['keywords']));
        foreach ($words as $word) {
            if (str_contains($haystack, $word)) {
                return true;
            }
        }
        return false;
    }
}
