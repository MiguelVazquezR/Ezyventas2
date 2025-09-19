<script setup>
import { ref, computed } from 'vue'; // Se importa computed
import { usePage } from '@inertiajs/vue3'; // Se importa usePage para acceder a los permisos
import AppMenuItem from './AppMenuItem.vue';

const model = ref([
    {
        items: [
            { label: 'Inicio', icon: 'pi pi-fw pi-home', to: route('dashboard'), routeName: 'dashboard' },
            { label: 'Punto de venta', icon: 'pi pi-fw pi-wallet', to: route('pos.index'), routeName: 'pos.*' },
            { label: 'Productos', icon: 'pi pi-fw pi-building', to: route('products.index'), routeName: 'products.*' },
            { label: 'Gastos', icon: 'pi pi-fw pi-calendar-times', to: route('expenses.index'), routeName: 'expenses.*' },
            // { label: 'Bonos', icon: 'pi pi-fw pi-wallet', to: route('bonuses.index'), routeName: 'bonuses.*', permission: 'ver_bonos' },
            // {
            //     label: 'Configuraciones', icon: 'pi pi-fw pi-cog',
            //     items: [
            //         {
            //             label: 'Roles y permisos',
            //             icon: 'pi pi-key',
            //             to: route('settings.roles-permissions.index'),
            //             routeName: 'settings.roles-permissions.*',
            //             permission: 'ver_roles_permisos'
            //         },
            //         {
            //             label: 'Días festivos',
            //             icon: 'pi pi-calendar-plus',
            //             to: route('settings.holidays.index'),
            //             routeName: 'settings.holidays.*',
            //             permission: 'ver_festivos'
            //         },
            //         {
            //             label: 'Horarios del personal',
            //             icon: 'pi pi-clock',
            //             to: route('settings.schedules.index'),
            //             routeName: 'settings.schedules.*',
            //             permission: 'ver_horarios'
            //         },
            //     ]
            // },
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