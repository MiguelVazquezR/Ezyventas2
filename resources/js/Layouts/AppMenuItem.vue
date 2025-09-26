<script setup>
import { useLayout } from '@/Layouts/composables/layout';
import { Link } from '@inertiajs/vue3';
import { onBeforeMount, ref, watch, computed } from 'vue';

const { layoutState, setActiveMenuItem, toggleMenu } = useLayout();

const props = defineProps({
    item: {
        type: Object,
        default: () => ({})
    },
    index: {
        type: Number,
        default: 0
    },
    root: {
        type: Boolean,
        default: true
    },
    parentItemKey: {
        type: String,
        default: null
    }
});

const isActiveMenu = ref(false);
const itemKey = ref(null);

/**
 * Propiedad computada que comprueba si el enlace del menú debe estar activo.
 * Utiliza el helper `route()` de Inertia/Ziggy.
 */
const isLinkActive = computed(() => {
    if (!props.item.routeName) {
        return false;
    }

    // El helper `route()` está disponible globalmente en Inertia.
    // 1. Comprobamos si la ruta actual coincide con un patrón (ej. 'users.*' para users.create, users.edit).
    //    Esto es útil para mantener el menú padre activo cuando estás en una sub-página.
    // 2. Si no, comprobamos si la ruta actual tiene el nombre exacto.
    return route().current(props.item.routeName);
});


onBeforeMount(() => {
    itemKey.value = props.parentItemKey ? props.parentItemKey + '-' + props.index : String(props.index);

    const activeItem = layoutState.activeMenuItem;

    // Comprobamos si este submenú debe estar abierto al cargar la página
    const isSubmenuActiveOnLoad = props.item.items && props.item.items.some(child =>
        route().current(child.routeName)
    );

    isActiveMenu.value = (activeItem === itemKey.value || (activeItem && activeItem.startsWith(itemKey.value + '-'))) || isSubmenuActiveOnLoad;
});

watch(
    () => layoutState.activeMenuItem,
    (newVal) => {
        isActiveMenu.value = newVal === itemKey.value || newVal.startsWith(itemKey.value + '-');
    }
);

function itemClick(event, item) {
    if (item.disabled) {
        event.preventDefault();
        return;
    }

    if ((item.to || item.url) && (layoutState.staticMenuMobileActive || layoutState.overlayMenuActive)) {
        toggleMenu();
    }

    if (item.command) {
        item.command({ originalEvent: event, item: item });
    }

    const foundItemKey = item.items ? (isActiveMenu.value ? props.parentItemKey : itemKey) : itemKey.value;

    setActiveMenuItem(foundItemKey);
}
</script>

<template>
    <li :class="{ 'layout-root-menuitem': root, 'active-menuitem': isActiveMenu || isLinkActive }">
        <div v-if="root && item.visible !== false" class="layout-menuitem-root-text text-gray-100">{{ item.label }}
        </div>

        <!-- Elemento del menú que es un submenú (no es un enlace) -->
        <a v-if="(!item.to || item.items) && item.visible !== false" :href="item.url"
            @click.prevent="itemClick($event, item)" :class="item.class"
            class="text-gray-300 hover:text-gray-600 dark:hover:text-gray-300" :target="item.target" tabindex="0">
            <i :class="item.icon" class="layout-menuitem-icon"></i>
            <span class="layout-menuitem-text">{{ item.label }}</span>
            <i class="pi pi-fw pi-angle-down layout-submenu-toggler" v-if="item.items"></i>
        </a>

        <Link v-if="item.to && !item.items && item.visible !== false" @click="itemClick($event, item)"
            :class="[item.class, { 'text-gray-600 bg-gray-50 dark:text-gray-300 dark:bg-neutral-900 font-semibold': isLinkActive }]"
            class="text-gray-300 hover:text-gray-600 dark:hover:text-gray-300" tabindex="0" :href="item.to">
        <i :class="item.icon" class="layout-menuitem-icon"></i>
        <span class="layout-menuitem-text">{{ item.label }}</span>
        <i class="pi pi-fw pi-angle-down layout-submenu-toggler" v-if="item.items"></i>
        </Link>

        <Transition v-if="item.items && item.visible !== false" name="layout-submenu">
            <ul v-show="root ? true : isActiveMenu" class="layout-submenu">
                <app-menu-item v-for="(child, i) in item.items" :key="child" :index="i" :item="child"
                    :parentItemKey="itemKey" :root="false"></app-menu-item>
            </ul>
        </Transition>
    </li>
</template>
