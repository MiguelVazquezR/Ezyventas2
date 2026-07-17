<script setup>
import { ref, computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppMenuItem from './AppMenuItem.vue';

const model = ref([
    {
        items: [
            { label: 'Inicio', icon: 'pi pi-home', to: route('dashboard'), routeName: 'dashboard' },
            { label: 'Punto de venta', icon: 'pi pi-shop', to: route('pos.index'), routeName: 'pos.*', permission: 'pos.access' },
            { label: 'Reporte financiero', icon: 'pi pi-chart-bar', to: route('financial-control.index'), routeName: 'financial-control.*', permission: 'financial_reports.access' },
            { label: 'Historial de ventas', icon: 'pi pi-history', to: route('transactions.index'), routeName: 'transactions.*', permission: 'transactions.access' },
            { label: 'Productos', icon: 'pi pi-barcode', to: route('products.index'), routeName: 'products.*', permission: 'products.access' },
            { label: 'Gastos', icon: 'pi pi-arrow-up-right', to: route('expenses.index'), routeName: 'expenses.*', permission: 'expenses.access' },
            { label: 'Clientes', icon: 'pi pi-users', to: route('customers.index'), routeName: 'customers.*', permission: 'customers.access' },
            {
                label: 'Facturación', icon: 'pi pi-file', routeName: 'billing.*', permission: 'invoices.access',
                items: [
                    {
                        label: 'Resumen',
                        icon: 'pi pi-chart-bar',
                        to: route('billing.dashboard'),
                        routeName: 'billing.dashboard',
                    },
                    {
                        label: 'Comprobantes',
                        icon: 'pi pi-receipt',
                        to: route('billing.invoices.index'),
                        routeName: 'billing.invoices.*',
                    },
                    {
                        label: 'Configuración',
                        icon: 'pi pi-cog',
                        to: route('billing.settings.index'),
                        routeName: 'billing.settings.*',
                    },
                ],
            },
            {
                label: 'Servicios', icon: 'pi pi-wrench', module: 'module_services',
                items: [
                    {
                        label: 'Catálogo de servicios',
                        icon: 'pi pi-list',
                        to: route('services.index'),
                        routeName: 'services.*',
                        permission: 'services.catalog.access',
                        module: 'module_services',
                    },
                    {
                        label: 'Órdenes de servico',
                        icon: 'pi pi-clipboard',
                        to: route('service-orders.index'),
                        routeName: 'service-orders.*',
                        permission: 'services.orders.access',
                        module: 'module_services',
                    },
                ]
            },
            { label: 'Cotizaciones', icon: 'pi pi-file-check', to: route('quotes.index'), routeName: 'quotes.*', permission: 'quotes.access', module: 'module_quotes' },
            {
                label: 'Tienda en línea', icon: 'pi pi-globe',
                items: [
                    {
                        label: 'Configuración',
                        icon: 'pi pi-cog',
                        to: route('online-store.config'),
                        routeName: 'online-store.config',
                        permission: 'online_store.config.access'
                    },
                    {
                        label: 'Pedidos',
                        icon: 'pi pi-shopping-cart',
                        to: route('online-store.orders.index'),
                        routeName: 'online-store.orders.*',
                        permission: 'online_store.orders.access'
                    },
                ]
            },
            {
                label: 'Cajas', icon: 'pi pi-dollar', module: 'module_cash_registers',
                items: [
                    {
                        label: 'Cajas registradoras',
                        icon: 'pi pi-inbox',
                        to: route('cash-registers.index'),
                        routeName: 'cash-registers.*',
                        permission: 'cash_registers.access',
                        module: 'module_cash_registers',
                    },
                    {
                        label: 'Historial de cortes',
                        icon: 'pi pi-calendar-plus',
                        to: route('cash-register-sessions.index'),
                        routeName: 'cash-register-sessions.*',
                        permission: 'cash_registers.sessions.access',
                        module: 'module_cash_registers',
                    },
                ]
            },
            {
                label: 'Configuraciones', icon: 'pi pi-cog', module: 'module_settings',
                items: [
                    {
                        label: 'Generales',
                        icon: 'pi pi-sliders-h',
                        to: route('settings.index'),
                        routeName: 'settings.*',
                        permission: 'settings.generals.access',
                        module: 'module_settings',
                    },
                    {
                        label: 'Roles y permisos',
                        icon: 'pi pi-key',
                        to: route('roles.index'),
                        routeName: 'roles.*',
                        permission: 'settings.roles_permissions.access',
                        module: 'module_settings',
                    },
                    {
                        label: 'Usuarios',
                        icon: 'pi pi-user',
                        to: route('users.index'),
                        routeName: 'users.*',
                        permission: 'settings.users.access',
                        module: 'module_settings',
                    },
                    {
                        label: 'Plantillas personalizadas',
                        icon: 'pi pi-palette',
                        to: route('print-templates.index'),
                        routeName: 'print-templates.*',
                        permission: 'settings.templates.access',
                        module: 'module_settings',
                    },
                ]
            },
        ]
    },
]);

// Permisos del usuario (filtrado por módulos activos en el backend para el dueño)
const userPermissions = computed(() => usePage().props.auth.permissions || []);

// Módulos activos según la suscripción (ej. ['module_pos', 'module_products', ...])
const activeModules = computed(() => usePage().props.auth.active_modules || []);

// Filtra el menú por permisos Y módulos activos
const filterMenu = (items) => {
    return items.reduce((acc, item) => {
        // 1. Comprobar permiso
        const hasPermission = !item.permission || userPermissions.value.includes(item.permission);

        // 2. Comprobar módulo activo
        const moduleKey = item.module;
        const hasModule = !moduleKey || activeModules.value.includes(moduleKey);

        if (hasPermission && hasModule) {
            if (item.items) {
                const filteredChildren = filterMenu(item.items);
                if (filteredChildren.length > 0) {
                    acc.push({ ...item, items: filteredChildren });
                }
            } else {
                acc.push(item);
            }
        }
        return acc;
    }, []);
};

const filteredModel = computed(() => filterMenu(model.value));

// Obtener el usuario autenticado
const authUser = computed(() => usePage().props.auth.user);

// Comprobar si es Super Admin (Suscripción 1)
const isSuperAdmin = computed(() => authUser.value.branch?.subscription_id === 1);

// Definir el modelo del menú de administración
const adminModel = ref([
    {
        label: 'Administración',
        items: [
            {
                label: 'Reportes',
                icon: 'pi pi-chart-line',
                to: route('admin.reports.index'),
                routeName: 'admin.reports.*'
            },
            {
                label: 'Pagos pendientes',
                icon: 'pi pi-clock',
                to: route('admin.payments.index'),
                routeName: 'admin.payments.*'
            },
            {
                label: 'Suscriptores',
                icon: 'pi pi-crown', 
                to: route('admin.subscriptions.index'),
                routeName: 'admin.subscriptions.*'
            },
            {
                label: 'Ítems de planes',
                icon: 'pi pi-box', 
                to: route('admin.plan-items.index'),
                routeName: 'admin.plan-items.*'
            },
            {
                label: 'Novedades',
                icon: 'pi pi-megaphone',
                to: route('admin.release-notes.index'),
                routeName: 'admin.release-notes.*'
            },
            {
                label: 'Referidos',
                icon: 'pi pi-users',
                items: [
                    {
                        label: 'Historial',
                        icon: 'pi pi-list',
                        to: route('admin.referrals.index'),
                        routeName: 'admin.referrals.index'
                    },
                    {
                        label: 'Configuración',
                        icon: 'pi pi-cog',
                        to: route('admin.referrals.settings'),
                        routeName: 'admin.referrals.settings'
                    },
                ]
            },
        ]
    },
]);
</script>

<template>
    <ul class="layout-menu">
        <!-- Se itera sobre 'filteredModel' en lugar de 'model'. -->
        <template v-for="(item, i) in filteredModel" :key="item">
            <app-menu-item v-if="!item.separator" :item="item" :index="i"></app-menu-item>
            <li v-if="item.separator" class="menu-separator"></li>
        </template>
        <template v-if="isSuperAdmin">
            <!-- Separador -->
            <li class="menu-separator"></li>
            <!-- Itera sobre el modelo de menú de admin -->
            <template v-for="(item, i) in adminModel" :key="item">
                <app-menu-item v-if="!item.separator" :item="item" :index="i"></app-menu-item>
            </template>
        </template>
    </ul>
</template>

<style lang="scss" scoped></style>