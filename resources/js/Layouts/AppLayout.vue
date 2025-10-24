<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { useLayout } from '@/Layouts/composables/layout';
import { computed, onMounted, onUnmounted, ref, watch, provide } from 'vue';
import AppFooter from './AppFooter.vue';
import AppSidebar from './AppSidebar.vue';
import AppTopbar from './AppTopbar.vue';
import { useToast } from 'primevue/usetoast';
// --- INICIO: Añadidos para el banner ---
import { Link } from '@inertiajs/vue3';
// --- FIN: Añadidos para el banner ---

defineProps({
    title: String,
});

const page = usePage();

// --- INICIO: Lógica del Banner de Suscripción ---
const subscriptionWarning = computed(() => page.props.auth.subscriptionWarning);
const isOwner = computed(() => page.props.auth.is_subscription_owner);

// NUEVO: Clases dinámicas para el banner (rojo si expira, amarillo si está por vencer)
const bannerClasses = computed(() => {
    if (!subscriptionWarning.value) return '';
    return subscriptionWarning.value.isExpired
        ? 'bg-red-50 border-red-500 text-red-700' // Rojo para expirado
        : 'bg-yellow-50 border-yellow-500 text-yellow-700'; // Amarillo para advertencia
});

const bannerIcon = computed(() => {
    if (!subscriptionWarning.value) return '';
    return subscriptionWarning.value.isExpired
        ? 'pi pi-ban' // Icono de "prohibido" o "expirado"
        : 'pi pi-exclamation-triangle'; // Icono de advertencia
});

const bannerTitle = computed(() => {
    if (!subscriptionWarning.value) return '';
    return subscriptionWarning.value.isExpired
        ? 'Suscripción Expirada'
        : 'Suscripción por Vencer';
});

const bannerButtonSeverity = computed(() => {
    if (!subscriptionWarning.value) return 'secondary';
    return subscriptionWarning.value.isExpired
        ? 'danger'
        : 'warning';
});
// --- FIN: Lógica del Banner de Suscripción ---

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
            <!-- --- INICIO: Banner de Advertencia de Suscripción (MODIFICADO) --- -->
            <div v-if="subscriptionWarning && isOwner" 
                 :class="bannerClasses"
                 class="border-l-4 p-1 sticky top-0 z-50 shadow-md mb-2 rounded-lg" 
                 role="alert">
                <div class="flex items-center justify-between max-w-7xl mx-auto py-1 px-2">
                    <div class="flex items-center">
                        <i :class="bannerIcon" class="!text-xl mr-3"></i>
                        <div>
                            <p class="font-bold m-0">{{ bannerTitle }}</p>
                            <p class="text-sm m-0">
                                {{ subscriptionWarning.message }}
                            </p>
                        </div>
                    </div>
                    <Link :href="route('subscription.show')" class="ml-4 flex-shrink-0">
                        <Button label="Renovar ahora" :severity="bannerButtonSeverity" size="small" />
                    </Link>
                </div>
            </div>
            <!-- --- FIN: Banner de Advertencia de Suscripción --- -->

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