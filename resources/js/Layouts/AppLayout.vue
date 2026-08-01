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
import AiChatDrawer from '@/Components/AiChatDrawer.vue';

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

// ── AI Chat Drawer ──
const aiDrawerVisible = ref(false);

const toast = useToast();

watch(isSidebarActive, (newVal) => {
    if (newVal) {
        bindOutsideClickListener();
    } else {
        unbindOutsideClickListener();
    }
});

let removeFlashListener = null;

/**
 * Calculate toast duration based on message length so users have
 * enough time to read longer error/warning messages.
 *
 * Formula: 60 ms per character, with a floor of 6 s and a ceiling of 14 s.
 * ~100 chars → 6 s | ~150 chars → 9 s | 200+ chars → 12-14 s
 */
const toastLife = (detail) => Math.max(6000, Math.min(14000, (detail || '').length * 60));

const handleFlashMessages = (event) => {
    const flash = event.detail.page.props.flash;
    if (flash) {
        if (flash.success) {
            toast.add({ severity: 'success', summary: 'Éxito', detail: flash.success, life: toastLife(flash.success) });
        }
        if (flash.error) {
            toast.add({ severity: 'error', summary: 'Error', detail: flash.error, life: toastLife(flash.error) });
        }
        if (flash.warning) {
            toast.add({ severity: 'warn', summary: 'Advertencia', detail: flash.warning, life: toastLife(flash.warning) });
        }
        if (flash.info) {
            toast.add({ severity: 'info', summary: 'Información', detail: flash.info, life: toastLife(flash.info) });
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
            <!-- Banner de Suscripción -->
            <div v-if="subscriptionWarning" 
                 :class="bannerClasses"
                 class="border-l-4 p-px sticky top-0 z-50 shadow-md mb-2 rounded-lg" 
                 role="alert">
                <div class="flex items-center justify-between max-w-7xl mx-auto py-px px-2">
                    <div class="flex items-center">
                        <i :class="bannerIcon" class="!text-lg mr-3"></i>
                        <div>
                            <p class="font-bold text-sm m-0">{{ bannerTitle }}</p>
                            <p class="text-xs m-0">
                                {{ subscriptionWarning.message }}
                            </p>
                        </div>
                    </div>
                    <Link v-if="isOwner" :href="route('subscription.manage')" class="ml-4 flex-shrink-0">
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
    <ConfirmDialog class="max-w-2xl" />

    <!-- --- INICIO: NUEVO MODAL AÑADIDO --- -->
    <SessionClosedModal 
        :visible="sessionClosedModalVisible" 
        :event-data="closedSessionData"
        @update:visible="sessionClosedModalVisible = $event"
    />
    <!-- --- FIN: NUEVO MODAL AÑADIDO --- -->

    <!-- ── AI Chat floating trigger button ── -->
    <div class="ai-glow-ring">
        <button
            class="w-12 h-12 rounded-full bg-[#1a1a1a] dark:bg-[#232323] shadow-lg hover:scale-105 active:scale-95 flex items-center justify-center transition-transform duration-200 border-0 cursor-pointer z-10"
            @click="aiDrawerVisible = true"
            :aria-label="'Abrir asistente IA'"
        >
            <i class="pi pi-sparkles !text-white !text-xl" />
        </button>
    </div>

    <!-- ── AI Chat Drawer ── -->
    <AiChatDrawer v-model:visible="aiDrawerVisible" />
</template>

<style scoped>
/* ── AI Glow Ring Animation ── */
.ai-glow-ring {
    position: fixed;
    bottom: 0.5rem;
    right: 0.5rem;
    z-index: 50;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 52px;
    height: 52px;
    border-radius: 50%;
    background: conic-gradient(
        from 0deg,
        #f97316,
        #f59e0b,
        #eab308,
        #f43f5e,
        #f97316
    );
    background-size: 300% 300%;
    background-position: 0% 0%;
    padding: 2px;
    animation: ai-ring-shift 4s linear infinite;
    /* The ring colors flow around, but the div itself does NOT rotate,
       so the button inside stays perfectly still. */
}

/* Outer glow halo that pulses in brightness */
.ai-glow-ring::before {
    content: '';
    position: absolute;
    inset: -6px;
    border-radius: 50%;
    background: conic-gradient(
        from 180deg,
        #f97316,
        #f59e0b,
        #eab308,
        #f43f5e,
        #f97316
    );
    background-size: 300% 300%;
    background-position: 50% 50%;
    filter: blur(16px);
    opacity: 0.3;
    z-index: -1;
    animation: ai-glow-pulse 2.5s ease-in-out infinite;
}

/* Shift background position so the conic gradient colors flow around the ring */
@keyframes ai-ring-shift {
    0%   { background-position: 0% 0%; }
    50%  { background-position: 100% 100%; }
    100% { background-position: 0% 0%; }
}

/* Pulse the glow brightness up and down */
@keyframes ai-glow-pulse {
    0%, 100% { opacity: 0.2; filter: blur(16px); }
    50%      { opacity: 0.6; filter: blur(22px); }
}
</style>
