<script setup>
import { Head, router, usePage } from '@inertiajs/vue3'; // Se añade usePage
import { useLayout } from '@/Layouts/composables/layout';
import { computed, onMounted, onUnmounted, ref, watch, provide } from 'vue'; // Se añade provide
import AppFooter from './AppFooter.vue';
import AppSidebar from './AppSidebar.vue';
import AppTopbar from './AppTopbar.vue';
import { useToast } from 'primevue/usetoast';

defineProps({
    title: String,
});
// Se obtiene la información de la página, incluyendo los datos compartidos globalmente.
const page = usePage();

// Se provee la sesión activa para que cualquier componente hijo (como PaymentModal) pueda inyectarla.
// Se usa 'computed' para que sea reactivo si la sesión cambia.
provide('activeSession', computed(() => page.props.activeSession));

const { layoutConfig, layoutState, isSidebarActive } = useLayout();
const outsideClickListener = ref(null);

const toast = useToast();

watch(isSidebarActive, (newVal) => {
    if (newVal) {
        bindOutsideClickListener();
    } else {
        unbindOutsideClickListener();
    }
});

let removeFlashListener = null;

const handleFlashMessages = (event) => {
    const flash = event.detail.page.props.flash;
    if (flash) {
        if (flash.success) {
            toast.add({ severity: 'success', summary: 'Éxito', detail: flash.success, life: 5000 });
        }
        if (flash.error) {
            toast.add({ severity: 'error', summary: 'Error', detail: flash.error, life: 5000 });
        }
        if (flash.warning) {
            toast.add({ severity: 'warn', summary: 'Advertencia', detail: flash.warning, life: 5000 });
        }
        if (flash.info) {
            toast.add({ severity: 'info', summary: 'Información', detail: flash.info, life: 5000 });
        }
    }
};

onMounted(() => {
    removeFlashListener = router.on('success', handleFlashMessages);
});

onUnmounted(() => {
    if (removeFlashListener) {
        removeFlashListener();
    }
});

const containerClass = computed(() => {
    return {
        'layout-overlay': layoutConfig.menuMode === 'overlay',
        'layout-static': layoutConfig.menuMode === 'static',
        'layout-static-inactive': layoutState.staticMenuDesktopInactive && layoutConfig.menuMode === 'static',
        'layout-overlay-active': layoutState.overlayMenuActive,
        'layout-mobile-active': layoutState.staticMenuMobileActive
    };
});

function bindOutsideClickListener() {
    if (!outsideClickListener.value) {
        outsideClickListener.value = (event) => {
            if (isOutsideClicked(event)) {
                layoutState.overlayMenuActive = false;
                layoutState.staticMenuMobileActive = false;
                layoutState.menuHoverActive = false;
            }
        };
        document.addEventListener('click', outsideClickListener.value);
    }
}

function unbindOutsideClickListener() {
    if (outsideClickListener.value) {
        document.removeEventListener('click', outsideClickListener);
        outsideClickListener.value = null;
    }
}

function isOutsideClicked(event) {
    const sidebarEl = document.querySelector('.layout-sidebar');
    const topbarEl = document.querySelector('.layout-menu-button');

    return !(sidebarEl.isSameNode(event.target) || sidebarEl.contains(event.target) || topbarEl.isSameNode(event.target) || topbarEl.contains(event.target));
}
</script>

<template>
    <div class="layout-wrapper" :class="containerClass">

        <Head :title="title" />
        <app-topbar></app-topbar>
        <app-sidebar></app-sidebar>
        <div class="layout-main-container">
            <main class="layout-main">
                <slot />
            </main>
            <!-- <app-footer></app-footer> -->
        </div>
        <div class="layout-mask animate-fadein"></div>
    </div>
    <Toast />
    <ConfirmDialog />
</template>