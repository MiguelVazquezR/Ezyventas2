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

const handleFlashMessages = (event) => {
    const flash = event.detail.page.props.flash;
    if (flash) {
        if (flash.success) {
            toast.add({ severity: 'success', summary: 'Éxito', detail: flash.success, life: 6000, escape: false });
        }
        if (flash.error) {
            toast.add({ severity: 'error', summary: 'Error', detail: flash.error, life: 6000, escape: false });
        }
        if (flash.warning) {
            toast.add({ severity: 'warn', summary: 'Advertencia', detail: flash.warning, life: 6000, escape: false });
        }
        if (flash.info) {
            toast.add({ severity: 'info', summary: 'Información', detail: flash.info, life: 6000, escape: false });
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
    <Toast :escape="false" />
    <ConfirmDialog class="max-w-2xl" />

    <!-- --- INICIO: NUEVO MODAL AÑADIDO --- -->
    <SessionClosedModal 
        :visible="sessionClosedModalVisible" 
        :event-data="closedSessionData"
        @update:visible="sessionClosedModalVisible = $event"
    />
    <!-- --- FIN: NUEVO MODAL AÑADIDO --- -->

    <!-- ── AI Chat floating trigger button ── -->
    <button
        type="button"
        class="ai-fab"
        @click="aiDrawerVisible = true"
        :aria-label="'Abrir asistente IA'"
    >
        <!-- Lava lamp ring: spinning conic gradient + drifting color blobs -->
        <span class="ai-fab-lava" aria-hidden="true"></span>
        <span class="ai-fab-blob ai-fab-blob--a" aria-hidden="true"></span>
        <span class="ai-fab-blob ai-fab-blob--b" aria-hidden="true"></span>

        <!-- Matte black core with the agent icon -->
        <span class="ai-fab-core">
            <i class="pi pi-sparkles ai-fab-icon" aria-hidden="true" />
        </span>
    </button>

    <!-- ── AI Chat Drawer ── -->
    <AiChatDrawer v-model:visible="aiDrawerVisible" />
</template>

<style scoped>
/* ── AI floating trigger: matte black core with a lava-lamp ring ── */
.ai-fab {
    position: fixed;
    bottom: 0.5rem;
    right: 0.5rem;
    z-index: 50;
    width: 3rem;
    height: 3rem;
    padding: 0;
    border: 0;
    border-radius: 9999px;
    background: transparent;
    cursor: pointer;
    isolation: isolate;
    transition: transform 0.25s ease;
    -webkit-tap-highlight-color: transparent;
}

.ai-fab:hover {
    transform: scale(1.06);
}

.ai-fab:active {
    transform: scale(0.94);
}

.ai-fab:focus-visible {
    outline: 2px solid #c084fc;
    outline-offset: 4px;
}

/* Base ring: full color wheel rotating behind the core */
.ai-fab-lava {
    position: absolute;
    inset: -3px;
    border-radius: 9999px;
    background: conic-gradient(
        from 0deg,
        #ff5f6d,
        #ff9a3c,
        #ffe066,
        #57e389,
        #22d3ee,
        #6366f1,
        #c084fc,
        #f472b6,
        #ff5f6d
    );
    filter: blur(5px) saturate(1.5);
    animation: lava-spin 9s linear infinite;
    transition: filter 0.3s ease;
    will-change: transform;
}

.ai-fab:hover .ai-fab-lava {
    filter: blur(6px) saturate(1.8);
}

/* Organic color blobs drifting on top of the ring */
.ai-fab-blob {
    position: absolute;
    inset: -3px;
    border-radius: 9999px;
    pointer-events: none;
    mix-blend-mode: screen;
}

.ai-fab-blob--a {
    background:
        radial-gradient(circle at 30% 22%, rgba(244, 114, 182, 0.95), transparent 55%),
        radial-gradient(circle at 78% 82%, rgba(34, 211, 238, 0.9), transparent 55%);
    filter: blur(4px);
    animation: lava-swirl-a 7s ease-in-out infinite;
    will-change: transform, opacity;
}

.ai-fab-blob--b {
    background:
        radial-gradient(circle at 72% 24%, rgba(250, 204, 21, 0.85), transparent 55%),
        radial-gradient(circle at 24% 76%, rgba(139, 92, 246, 0.9), transparent 55%);
    filter: blur(4px);
    animation: lava-swirl-b 11s ease-in-out infinite;
    will-change: transform, opacity;
}

/* Opaque black core keeps the icon crisp above the glow */
.ai-fab-core {
    position: absolute;
    inset: 3px;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
    background: #050505;
    box-shadow:
        inset 0 0 0 1px rgba(255, 255, 255, 0.08),
        inset 0 1px 0 rgba(255, 255, 255, 0.12);
}

.ai-fab-icon {
    display: inline-block;
    font-size: 1.25rem;
    color: #ffffff;
    filter: drop-shadow(0 0 6px rgba(244, 114, 182, 0.55));
    animation: lava-icon-breathe 3.5s ease-in-out infinite;
}

@keyframes lava-spin {
    to {
        transform: rotate(360deg);
    }
}

@keyframes lava-swirl-a {
    0%,
    100% {
        transform: rotate(0deg) scale(1);
        opacity: 0.8;
    }

    50% {
        transform: rotate(200deg) scale(1.1);
        opacity: 1;
    }
}

@keyframes lava-swirl-b {
    0%,
    100% {
        transform: rotate(0deg) scale(1.06);
        opacity: 0.75;
    }

    50% {
        transform: rotate(-180deg) scale(0.94);
        opacity: 1;
    }
}

@keyframes lava-icon-breathe {
    0%,
    100% {
        transform: scale(1);
    }

    50% {
        transform: scale(1.08);
    }
}

@media (prefers-reduced-motion: reduce) {
    .ai-fab-lava,
    .ai-fab-blob,
    .ai-fab-icon {
        animation: none;
    }
}
</style>

