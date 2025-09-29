<script setup>
import { ref, computed } from 'vue'; // Se importa computed
import { usePage } from '@inertiajs/vue3'; // Se importa usePage para acceder a los permisos
import AppMenuItem from './AppMenuItem.vue';

const model = ref([
    {
        items: [
            { label: 'Inicio', icon: 'pi pi-fw pi-home', to: route('dashboard'), routeName: 'dashboard' },
            { label: 'Reporte financiero', icon: 'pi pi-key', to: route('financial-control.index'), routeName: 'financial-control.*', permission: 'financial_reports.access' },
            { label: 'Punto de venta', icon: 'pi pi-fw pi-wallet', to: route('pos.index'), routeName: 'pos.*', permission: 'pos.access' },
            { label: 'Historial de ventas', icon: 'pi pi-fw pi-moon', to: route('transactions.index'), routeName: 'transactions.*', permission: 'transactions.access' },
            { label: 'Productos', icon: 'pi pi-fw pi-building', to: route('products.index'), routeName: 'products.*', permission: 'products.access' },
            { label: 'Gastos', icon: 'pi pi-fw pi-calendar-times', to: route('expenses.index'), routeName: 'expenses.*', permission: 'expenses.access' },
            { label: 'Clientes', icon: 'pi pi-fw pi-user', to: route('customers.index'), routeName: 'customers.*', permission: 'customers.access' },
            {
                label: 'Servicios', icon: 'pi pi-fw pi-cog',
                items: [
                    {
                        label: 'Catálogo de servicios',
                        icon: 'pi pi-key',
                        to: route('services.index'),
                        routeName: 'services.*',
                        permission: 'services.catalog.access'
                    },
                    {
                        label: 'Órdenes de servico',
                        icon: 'pi pi-calendar-plus',
                        to: route('service-orders.index'),
                        routeName: 'service-orders.*',
                        permission: 'services.orders.access'
                    },
                ]
            },
            { label: 'Cotizaciones', icon: 'pi pi-fw pi-user', to: route('quotes.index'), routeName: 'quotes.*' },
            {
                label: 'Cajas', icon: 'pi pi-fw pi-sun',
                items: [
                    {
                        label: 'Cajas registradoras',
                        icon: 'pi pi-calendar-plus',
                        to: route('cash-registers.index'),
                        routeName: 'cash-registers.*',
                        permission: 'cash_registers.access'
                    },
                    {
                        label: 'Historial de cortes',
                        icon: 'pi pi-calendar-plus',
                        to: route('cash-register-sessions.index'),
                        routeName: 'cash-register-sessions.*',
                        permission: 'cash_registers.sessions.access'
                    },
                ]
            },
            {
                label: 'Configuraciones', icon: 'pi pi-fw pi-cog',
                items: [
                    {
                        label: 'Generales',
                        icon: 'pi pi-key',
                        to: route('settings.index'),
                        routeName: 'settings.*',
                        permission: 'settings.generals.access'
                    },
                    {
                        label: 'Roles y permisos',
                        icon: 'pi pi-calendar-plus',
                        to: route('roles.index'),
                        routeName: 'roles.*',
                        permission: 'settings.roles_permissions.access'
                    },
                    {
                        label: 'Usuarios',
                        icon: 'pi pi-user',
                        to: route('users.index'),
                        routeName: 'users.*',
                        permission: 'settings.users.access'
                    },
                    {
                        label: 'Plantillas personalizadas',
                        icon: 'pi pi-user',
                        to: route('print-templates.index'),
                        routeName: 'print-templates.*',
                        permission: 'settings.templates.access'
                    },
                ]
            },
        ]
    },
]);

// Se crea una propiedad computada que filtra el menú.
const userPermissions = computed(() => usePage().props.auth.permissions || []);

const filterMenu = (items) => {
    return items.reduce((acc, item) => {
        // 1. Comprobar si el usuario tiene permiso para ver el elemento.
        const hasPermission = !item.permission || userPermissions.value.includes(item.permission);

        if (hasPermission) {
            // 2. Si el elemento tiene sub-elementos, filtrarlos recursivamente.
            if (item.items) {
                const filteredChildren = filterMenu(item.items);
                // Solo se añade el elemento padre si tiene al menos un hijo visible.
                if (filteredChildren.length > 0) {
                    acc.push({ ...item, items: filteredChildren });
                }
            } else {
                // Si es un enlace directo y tiene permiso, se añade.
                acc.push(item);
            }
        }
        return acc;
    }, []);
};

// El menú que se renderizará en el template será este, ya filtrado.
const filteredModel = computed(() => filterMenu(model.value));
</script>

<template>
    <ul class="layout-menu">
        <!-- Se itera sobre 'filteredModel' en lugar de 'model'. -->
        <template v-for="(item, i) in filteredModel" :key="item">
            <app-menu-item v-if="!item.separator" :item="item" :index="i"></app-menu-item>
            <li v-if="item.separator" class="menu-separator"></li>
        </template>
    </ul>
</template>

<style lang="scss" scoped></style>