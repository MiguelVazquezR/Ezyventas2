<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { useLayout } from '@/Layouts/composables/layout';
import { computed, onMounted, onUnmounted, ref, watch, provide } from 'vue';
import AppFooter from './AppFooter.vue';
import AppSidebar from './AppSidebar.vue';
import AppTopbar from './AppTopbar.vue';
import { useToast } from 'primevue/usetoast';
import { Link } from '@inertiajs/vue3';
import SessionClosedModal from '@/Components/SessionClosedModal.vue';

defineProps({
    title: String,
});

const page = usePage();

// --- Lógica del Banner de Suscripción ---
const subscriptionWarning = computed(() => page.props.auth.subscriptionWarning);
const isOwner = computed(() => page.props.auth.is_subscription_owner);
const bannerClasses = computed(() => {
    if (!subscriptionWarning.value) return '';
    return subscriptionWarning.value.isExpired
        ? 'bg-red-50 border-red-500 text-red-700'
        : 'bg-yellow-50 border-yellow-500 text-yellow-700';
});
const bannerIcon = computed(() => {
    if (!subscriptionWarning.value) return '';
    return subscriptionWarning.value.isExpired
        ? 'pi pi-ban'
        : 'pi pi-exclamation-triangle';
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
            toast.add({ severity: 'success', summary: 'Éxito', detail: flash.success, life: 6000 });
        }
        if (flash.error) {
            toast.add({ severity: 'error', summary: 'Error', detail: flash.error, life: 6000 });
        }
        if (flash.warning) {
            toast.add({ severity: 'warn', summary: 'Advertencia', detail: flash.warning, life: 6000 });
        }
        if (flash.info) {
            toast.add({ severity: 'info', summary: 'Información', detail: flash.info, life: 6000 });
        }
    }
};

onMounted(() => {
    removeFlashListener = router.on('success', handleFlashMessages);
    // --- AÑADIDO: Escuchar eventos al montar ---
    listenForSessionEvents(activeSession.value);
});

onUnmounted(() => {
    if (removeFlashListener) {
        removeFlashListener();
    }
    // --- AÑADIDO: Dejar de escuchar al desmontar ---
    leaveSessionChannel(activeSession.value);
});

// ... (resto de funciones de layout: containerClass, bindOutsideClickListener, etc. sin cambios) ...
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

// --- INICIO: NUEVA LÓGICA DE BROADCASTING ---

const sessionClosedModalVisible = ref(false);
const closedSessionData = ref(null);

// La sesión activa que viene de Inertia
const activeSession = computed(() => page.props.activeSession);

/**
 * Se suscribe al canal privado de la sesión activa.
 */
const listenForSessionEvents = (session) => {
    if (!session || !window.Echo) return;
    
    console.log(`[Echo] Subscribing to cash-register-session.${session.id}`);
    window.Echo.private(`cash-register-session.${session.id}`)
        .listen('.session.closed', (event) => {
            console.log('[Echo] Received session.closed event:', event);
            
            // Guardamos los datos del evento y mostramos el modal
            closedSessionData.value = event;
            sessionClosedModalVisible.value = true;
            
            // Forzamos una recarga de Inertia para actualizar el estado global.
            // Esto hará que `page.props.activeSession` se vuelva `null`
            // y el resto de la UI reaccione (ej. el AppTopbar).
            router.reload({ 
                preserveScroll: true,
                preserveState: true, // Evita que se pierda el estado de los componentes (ej. filtros)
                onSuccess: () => {
                    console.log('Inertia reloaded after session close.');
                }
            });
        });
};

/**
 * Abandona el canal de la sesión.
 */
const leaveSessionChannel = (session) => {
    if (!session || !window.Echo) return;
    console.log(`[Echo] Leaving cash-register-session.${session.id}`);
    window.Echo.leave(`cash-register-session.${session.id}`);
};

/**
 * Observa cambios en la sesión activa (ej. si el usuario se une o sale)
 * y actualiza las suscripciones de Echo.
 */
watch(activeSession, (newSession, oldSession) => {
    if (oldSession) {
        leaveSessionChannel(oldSession);
    }
    if (newSession) {
        listenForSessionEvents(newSession);
    }
});

// --- FIN: NUEVA LÓGICA DE BROADCASTING ---

</script>

<template>
    <div class="layout-wrapper" :class="containerClass">

        <Head :title="title" />
        <app-topbar></app-topbar>
        <app-sidebar></app-sidebar>
        <div class="layout-main-container">
            <!-- Banner de Suscripción (sin cambios) -->
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

            <main class="layout-main">
                <slot />
            </main>
        </div>
        <div class="layout-mask animate-fadein"></div>
    </div>
    <Toast />
    <ConfirmDialog />

    <!-- --- INICIO: NUEVO MODAL AÑADIDO --- -->
    <SessionClosedModal 
        :visible="sessionClosedModalVisible" 
        :event-data="closedSessionData"
        @update:visible="sessionClosedModalVisible = $event"
    />
    <!-- --- FIN: NUEVO MODAL AÑADIDO --- -->
</template>
