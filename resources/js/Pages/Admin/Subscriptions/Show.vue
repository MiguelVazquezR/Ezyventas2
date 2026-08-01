<script setup>
import { computed, ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { useConfirm } from 'primevue/useconfirm';
import AppLayout from '@/Layouts/AppLayout.vue';
import EditVersionItemsModal from './Partials/EditVersionItemsModal.vue';
import RegisterPaymentModal from './Partials/RegisterPaymentModal.vue';
import PaymentHistoryTable from './Partials/PaymentHistoryTable.vue';
import SubscriptionSettings from './Partials/SubscriptionSettings.vue';
import AdjustStampModal from '@/Components/AdjustStampModal.vue';

const confirm = useConfirm();

const props = defineProps({
    subscription: Object,
    planItems: Array,
    dynamicLimits: Array,
    dynamicModules: Array,
    subscriptionStatus: Object,
    fiscalDocumentUrl: String,
    settingsData: Object,
    planValue: Number,
    referrerActiveDiscountPct: Number,
    subscriptionCost: Number,
    aiUsage: Number,
    aiPercentage: Number,
    hasAiAgentModule: Boolean,
    fiscalProfiles: Array,
    allStampPurchases: Array,
});

// --- ESTADOS DE MODALES ---
const showEditVersionItemsModal = ref(false);
const showRegisterPaymentModal = ref(false);
const selectedVersion = ref(null);

// --- HANDLERS ---
const handleEditVersion = (version) => {
    selectedVersion.value = version;
    showEditVersionItemsModal.value = true;
};

const handleRegisterPayment = () => {
    showRegisterPaymentModal.value = true;
};

const handleDeleteVersion = (version) => {
    router.delete(route('admin.subscriptions.destroy-version', version.id), {
        preserveScroll: true,
        onError: (errors) => {
            console.error('Error al eliminar la versión:', errors);
        }
    });
};

const handleDeleteSubscription = () => {
    confirm.require({
        message: `¿Estás seguro de eliminar permanentemente "${props.subscription.commercial_name}" y todos sus recursos?`,
        header: 'Eliminar suscripción',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Cancelar',
        acceptLabel: 'Eliminar permanentemente',
        rejectClass: 'p-button-secondary p-button-outlined !rounded-xl !text-xs !uppercase !tracking-wider',
        acceptClass: 'p-button-danger !rounded-xl !text-xs !uppercase !tracking-wider',
        accept: () => {
            router.delete(route('admin.subscriptions.destroy', props.subscription.id), {
                onSuccess: () => {
                    // Toast handled by controller flash message
                },
                onError: (errors) => {
                    console.error('Error al eliminar la suscripción:', errors);
                },
            });
        },
        reject: () => {},
    });
};

// --- HELPER FUNCTIONS (ESTADOS REALES) ---
const getComputedStatus = (subscription) => {
    // Usamos la propiedad dinámica enviada por el modelo
    return subscription.computed_status || subscription.status;
};

const getStatusLabel = (data) => {
    const status = getComputedStatus(data);
    const statuses = {
        'activo': 'Activa',
        'expirado': 'Vencida',
        'suspendido': 'Suspendida',
        // Fallbacks por compatibilidad
        'active': 'Activa',
        'past_due': 'Atrasada',
        'canceled': 'Cancelada',
        'trialing': 'De prueba',
        'unpaid': 'Sin pago',
        'expired': 'Vencida'
    };
    return statuses[status] || status || 'Desconocido';
};

const getStatusColor = (data) => {
    const status = getComputedStatus(data);
    switch(status) {
        case 'activo':
        case 'active': 
            return 'bg-green-500 animate-pulse shadow-[0_0_8px_rgba(34,197,94,0.8)]';
        case 'trialing': 
            return 'bg-blue-500 animate-pulse';
        case 'past_due': 
        case 'unpaid': 
            return 'bg-orange-500 shadow-[0_0_8px_rgba(249,115,22,0.8)]';
        case 'expirado':
        case 'expired': 
            return 'bg-red-500 shadow-[0_0_8px_rgba(239,68,68,0.8)]';
        case 'suspendido':
        case 'canceled': 
            return 'bg-gray-500';
        default: 
            return 'bg-gray-500';
    }
};

const formatDate = (dateString) => {
    if (!dateString) return '--';
    return new Intl.DateTimeFormat('es-MX', { year: 'numeric', month: 'long', day: 'numeric' }).format(new Date(dateString));
};

const formatDateTime = (dateString) => {
    if (!dateString) return null;
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('es-MX', {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit',
    }).format(date);
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
};

// --- COMPUTEDS ---
const currentVersion = computed(() => {
    if (!props.subscription.versions || props.subscription.versions.length === 0) return null;
    return props.subscription.versions.find(v => {
        const start = new Date(v.start_date);
        const end = new Date(v.end_date);
        const now = new Date();
        return start <= now && end >= new Date(now.setHours(0,0,0,0));
    }) || props.subscription.versions[0];
});

// --- TESLA UI PT ---
const tagPt = { root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold border' } };

// ──────────────────────────────────────
// Stamp adjustment modal
// ──────────────────────────────────────
const showStampModal = ref(false);
const selectedFiscalProfile = ref(null);

function openStampModal(profile) {
    selectedFiscalProfile.value = profile;
    showStampModal.value = true;
}

function onStampAdjustSuccess() {
    showStampModal.value = false;
    selectedFiscalProfile.value = null;
}

function stampStatusLabel(status) {
    const labels = {
        'pending': 'Pendiente',
        'awaiting_review': 'En revisión',
        'approved': 'Aprobado',
        'rejected': 'Rechazado',
        'failed': 'Fallido',
        'stamps_applied': 'Acreditado',
    };
    return labels[status] || status;
}

function stampStatusSeverity(status) {
    const map = {
        'pending': 'warn',
        'awaiting_review': 'info',
        'approved': 'success',
        'rejected': 'danger',
        'failed': 'danger',
        'stamps_applied': 'success',
    };
    return map[status] || 'secondary';
}

function stampPaymentMethodLabel(method) {
    const labels = {
        'mercadopago': 'Mercado Pago',
        'bank_transfer': 'Transferencia',
        'manual_adjustment': 'Ajuste manual',
    };
    return labels[method] || method;
}
</script>

<template>
    <AppLayout :title="`Cliente: ${subscription.commercial_name}`">
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            
            <!-- Botón Volver -->
            <button @click="router.get(route('admin.subscriptions.index'))" class="flex items-center gap-2 text-xs uppercase tracking-widest font-bold text-gray-500 hover:text-gray-800 dark:hover:text-white transition-colors mb-4 bg-transparent border-none cursor-pointer p-0">
                <i class="pi pi-arrow-left !text-[10px]"></i> Volver al directorio
            </button>

            <!-- CONTENEDOR PRINCIPAL -->
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                
                <!-- Header del Suscriptor -->
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-8 pb-8 border-b border-gray-100 dark:border-[#3a3a3a]">
                    <div class="flex items-center gap-4">
                        <div class="w-16 h-16 rounded-full bg-gray-100 dark:bg-[#1a1a1a] flex items-center justify-center border border-gray-200 dark:border-[#3a3a3a]">
                            <i class="pi pi-building !text-2xl text-gray-400"></i>
                        </div>
                        <div>
                            <h1 class="text-3xl font-light tracking-tight text-gray-900 dark:text-white m-0 flex items-center gap-3">
                                {{ subscription.commercial_name }}
                                <!-- Carga dinámica de estado -->
                                <span :class="['w-2 h-2 rounded-full', getStatusColor(subscription)]" v-tooltip.top="getStatusLabel(subscription)"></span>
                            </h1>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">
                                {{ subscription.business_name || 'Sin razón social' }} • ID: {{ subscription.id }} • Registro: {{ formatDate(subscription.created_at) }}
                            </p>
                        </div>
                    </div>
                    
                    <Button
                        label="Eliminar suscripción"
                        icon="pi pi-trash"
                        severity="danger"
                        outlined
                        @click="handleDeleteSubscription"
                        class="!rounded-xl !text-xs !uppercase !tracking-wider shrink-0"
                    />

                </div>

                <div class="grid grid-cols-1 xl:grid-cols-3 gap-8">
                    
                    <!-- COLUMNA IZQUIERDA: Info General, Usuarios y Límites -->
                    <div class="space-y-6">
                        
                        <!-- Tarjeta de Contacto -->
                        <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                            <h2 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 mb-4">Información de Contacto</h2>
                            <ul class="space-y-3 m-0 p-0 list-none">
                                <li class="flex items-center gap-3 text-sm text-gray-700 dark:text-gray-300">
                                    <i class="pi pi-envelope text-gray-400"></i> {{ subscription.contact_email || 'No especificado' }}
                                </li>
                                <li class="flex items-center gap-3 text-sm text-gray-700 dark:text-gray-300">
                                    <i class="pi pi-phone text-gray-400"></i> {{ subscription.contact_phone || 'No especificado' }}
                                </li>
                                <li class="flex items-start gap-3 text-sm text-gray-700 dark:text-gray-300">
                                    <i class="pi pi-map-marker text-gray-400 mt-1"></i> 
                                    <span class="leading-tight">{{ subscription.address?.text || 'No especificado' }}</span>
                                </li>
                            </ul>
                        </div>

                        <!-- Tarjeta de Usuarios -->
                        <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                            <h2 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 mb-4 flex justify-between items-center">
                                Usuarios del sistema
                                <span class="font-mono text-gray-400">{{ subscription.users?.length || 0 }}</span>
                            </h2>
                            <div v-if="subscription.users && subscription.users.length > 0" class="space-y-3">
                                <div v-for="user in subscription.users" :key="user.id" class="flex items-center justify-between p-3 bg-white dark:bg-[#232323] rounded-xl border border-gray-100 dark:border-[#3a3a3a] transition-colors hover:border-gray-200 dark:hover:border-[#4a4a4a]">
                                    <div class="flex items-center gap-3 min-w-0">
                                        <div class="w-8 h-8 rounded-full bg-gray-200 dark:bg-[#1a1a1a] flex items-center justify-center shrink-0">
                                            <span class="text-[10px] font-bold text-gray-500 uppercase">{{ user.name?.charAt(0) }}</span>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 m-0 truncate">{{ user.name }}</p>
                                            <p class="text-[10px] text-gray-500 m-0 truncate">{{ user.email }}</p>
                                        </div>
                                    </div>
                                    <div class="text-right shrink-0 ml-3">
                                        <p class="text-[9px] uppercase tracking-widest font-bold text-gray-400 m-0 mb-0.5">Último ingreso</p>
                                        <p v-if="user.last_login_at" class="text-[10px] font-mono text-gray-500 dark:text-gray-400 m-0">
                                            {{ formatDateTime(user.last_login_at) }}
                                        </p>
                                        <p v-else class="text-[10px] text-gray-400 dark:text-gray-600 m-0 italic">
                                            Nunca
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="flex flex-col items-center justify-center py-6 text-center">
                                <i class="pi pi-users !text-2xl text-gray-300 dark:text-gray-600 mb-2"></i>
                                <p class="text-xs text-gray-400 m-0">Sin usuarios registrados</p>
                            </div>
                        </div>

                        <!-- Tarjeta de Telemetría: Uso de Límites Dinámicos -->
                        <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                            <h2 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 mb-4 flex justify-between items-center">
                                Consumo de recursos
                                <i class="pi pi-chart-pie text-gray-400"></i>
                            </h2>
                            <div class="space-y-4">
                                <div v-for="lim in dynamicLimits" :key="lim.key">
                                    <div class="flex justify-between text-xs mb-1">
                                        <span class="text-gray-700 dark:text-gray-300 flex items-center gap-1.5">
                                            <i :class="lim.icon + ' text-[10px] text-gray-500'"></i> {{ lim.label }}
                                        </span>
                                        <span class="font-mono" :class="lim.limit !== -1 && lim.usage >= lim.limit ? 'text-red-500' : 'text-gray-500'">
                                            {{ lim.usage }} / {{ lim.limit === -1 ? '∞' : lim.limit }}
                                        </span>
                                    </div>
                                    <div class="w-full bg-gray-200 dark:bg-[#2a2a2a] rounded-full h-1.5 overflow-hidden">
                                        <div class="h-1.5 rounded-full transition-all duration-500"
                                            :class="lim.limit !== -1 && lim.usage >= lim.limit ? 'bg-red-500' : 'bg-primary-500'"
                                            :style="`width: ${lim.limit === -1 ? 100 : Math.min((lim.usage / lim.limit) * 100, 100)}%`">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Tarjeta de Telemetría: Uso de IA -->
                        <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                            <h2 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 mb-4 flex justify-between items-center">
                                Asistente IA
                                <i class="pi pi-megaphone text-gray-400"></i>
                            </h2>
                            <div v-if="hasAiAgentModule">
                                <div class="flex justify-between text-xs mb-1">
                                    <span class="text-gray-700 dark:text-gray-300 flex items-center gap-1.5">
                                        <i class="pi pi-database text-[10px] text-gray-500"></i> Tokens usados este mes
                                    </span>
                                    <span class="font-mono text-gray-500">
                                        {{ aiUsage.toLocaleString() }}
                                    </span>
                                </div>
                                <div class="w-full bg-gray-200 dark:bg-[#2a2a2a] rounded-full h-1.5 overflow-hidden mb-3">
                                    <div class="h-1.5 rounded-full transition-all duration-500"
                                        :class="aiPercentage >= 100 ? 'bg-red-500' : 'bg-purple-500'"
                                        :style="`width: ${Math.min(aiPercentage, 100)}%`">
                                    </div>
                                </div>
                                <Link :href="route('admin.ai-agent.index')" class="text-[10px] uppercase tracking-widest font-bold text-purple-500 hover:text-purple-400 transition-colors">
                                    Gestionar límite global →
                                </Link>
                            </div>
                            <div v-else class="flex flex-col items-center justify-center py-4 text-center">
                                <i class="pi pi-lock !text-xl text-gray-300 dark:text-gray-600 mb-2"></i>
                                <p class="text-xs text-gray-400 m-0">Módulo no contratado</p>
                            </div>
                        </div>

                    </div>

                    <!-- COLUMNA DERECHA: Versiones y Pagos -->
                    <div class="xl:col-span-2 space-y-6">
                        
                        <!-- Panel Versión Actual (Telemetría grande) -->
                        <div v-if="currentVersion" class="bg-gray-50 dark:bg-[#1a1a1a] p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                            <div class="flex justify-between items-start mb-6">
                                <div>
                                    <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-1">Plan activo actualmente</h2>
                                    <p class="text-gray-900 dark:text-white text-sm m-0">
                                        Vigencia: <span class="font-bold text-primary-400">{{ formatDate(currentVersion.start_date) }}</span> al <span class="font-bold text-primary-400">{{ formatDate(currentVersion.end_date) }}</span>
                                    </p>
                                    
                                    <!-- Validación dinámica usando el estado computado -->
                                    <div v-if="getComputedStatus(subscription) === 'expirado' || getComputedStatus(subscription) === 'expired'" class="mt-2 inline-flex items-center gap-1.5 px-2 py-1 rounded bg-red-500/30 border border-red-600 text-red-500 text-xs font-bold uppercase tracking-widest">
                                        <i class="pi pi-exclamation-triangle !text-[10px]"></i> Suscripción vencida
                                    </div>
                                    <div v-else-if="subscriptionStatus.daysUntilExpiry <= 5" class="mt-2 inline-flex items-center gap-1.5 px-2 py-1 rounded bg-orange-900/30 border border-orange-800 text-orange-400 text-xs font-bold uppercase tracking-widest">
                                        <i class="pi pi-clock !text-[10px]"></i> Vence en {{ subscriptionStatus.daysUntilExpiry }} días
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-1">Valor del plan</p>
                                    <div v-if="referrerActiveDiscountPct > 0" class="flex flex-col items-end">
                                        <span class="text-lg text-gray-400 line-through font-mono">{{ formatCurrency(subscriptionCost) }}</span>
                                        <span class="text-4xl font-light tracking-tight text-purple-600 dark:text-purple-400">
                                            {{ formatCurrency(subscriptionCost * (1 - referrerActiveDiscountPct / 100)) }}
                                        </span>
                                        <span class="text-[9px] text-purple-500 font-bold uppercase tracking-widest mt-0.5">-{{ referrerActiveDiscountPct }}% desc. ref.</span>
                                    </div>
                                    <span v-else class="text-4xl font-light tracking-tight text-gray-900 dark:text-white">
                                        {{ formatCurrency(planValue) }}
                                    </span>
                                </div>
                            </div>

                            <!-- Listado de Módulos Dinámicos -->
                            <div class="mt-6">
                                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-3">Módulos del sistema</p>
                                <div class="flex flex-wrap gap-2">
                                    <Tag v-for="mod in dynamicModules" :key="mod.key"
                                        :value="mod.label" 
                                        :class="mod.is_active 
                                            ? '!bg-primary-500/20 !border-primary-500/30 !text-primary-400' 
                                            : '!bg-gray-100 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-gray-400 dark:!text-gray-600 opacity-60'"
                                        :pt="tagPt" />
                                </div>
                            </div>
                        </div>

                        <!-- Historial de Pagos por Versión -->
                        <PaymentHistoryTable
                            :versions="subscription.versions"
                            @edit-version="handleEditVersion"
                            @register-payment="handleRegisterPayment"
                            @delete-version="handleDeleteVersion"
                        />
                    </div>
                </div>

                <!-- Sección de Configuraciones -->
                <div class="mt-8">
                    <SubscriptionSettings
                        :settings-data="settingsData"
                        :subscription-id="subscription.id"
                    />
                </div>

                <!-- ── Timbres / Facturación ──────────── -->
                <div class="mt-8 pt-8 border-t border-gray-100 dark:border-[#3a3a3a]">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                            Facturación
                        </h2>
                        <Link
                            :href="route('admin.stamps.index')"
                            class="text-[10px] uppercase tracking-widest font-bold text-purple-500 hover:text-primary-500 transition-colors"
                        >
                            Panel global de timbres
                            <i class="pi pi-arrow-up-right !text-[8px] ml-1" />
                        </Link>
                    </div>

                    <!-- Fiscal profiles cards -->
                    <div v-if="fiscalProfiles && fiscalProfiles.length > 0" class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                        <div
                            v-for="profile in fiscalProfiles"
                            :key="profile.id"
                            class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]"
                        >
                            <div class="flex items-start justify-between mb-3">
                                <div>
                                    <p class="text-sm font-medium text-gray-900 dark:text-white m-0">{{ profile.razon_social }}</p>
                                    <p class="text-xs text-gray-400 m-0">RFC: {{ profile.rfc }}</p>
                                </div>
                                <Tag
                                    :value="profile.is_active ? 'Activo' : 'Inactivo'"
                                    :severity="profile.is_active ? 'success' : 'secondary'"
                                    class="!rounded-full"
                                />
                            </div>

                            <!-- Balance -->
                            <div v-if="profile.balanceError" class="text-xs text-red-500 mb-3">
                                {{ profile.balanceError }}
                            </div>
                            <div v-else-if="profile.balance" class="grid grid-cols-3 gap-3 mb-3">
                                <div class="text-center">
                                    <p class="text-[9px] uppercase tracking-wider text-gray-400 m-0">Disponibles</p>
                                    <p class="text-lg font-light text-gray-900 dark:text-white m-0">{{ profile.balance.stampsBalance ?? '—' }}</p>
                                </div>
                                <div class="text-center">
                                    <p class="text-[9px] uppercase tracking-wider text-gray-400 m-0">Usados</p>
                                    <p class="text-lg font-light text-gray-900 dark:text-white m-0">{{ profile.balance.stampsUsed ?? '—' }}</p>
                                </div>
                                <div class="text-center">
                                    <p class="text-[9px] uppercase tracking-wider text-gray-400 m-0">Asignados</p>
                                    <p class="text-lg font-light text-gray-900 dark:text-white m-0">{{ profile.balance.stampsAssigned ?? '—' }}</p>
                                </div>
                            </div>
                            <div v-else class="text-xs text-gray-400 mb-3">
                                Sin subcuenta PAC vinculada.
                            </div>

                            <Button
                                v-if="profile.is_active"
                                icon="pi pi-cog"
                                label="Ajustar timbres"
                                size="small"
                                severity="secondary"
                                class="!rounded-full w-full"
                                @click="openStampModal(profile)"
                            />
                        </div>
                    </div>

                    <!-- Empty state: no fiscal profiles -->
                    <div v-if="!fiscalProfiles || fiscalProfiles.length === 0" class="flex flex-col items-center justify-center py-12 px-4 text-center rounded-2xl bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a]">
                        <i class="pi pi-file !text-3xl text-gray-300 dark:text-gray-600 mb-3"></i>
                        <p class="text-sm text-gray-500 dark:text-gray-400 max-w-sm leading-relaxed m-0">
                            Este suscriptor no tiene perfiles fiscales registrados.
                        </p>
                        <p class="text-xs text-gray-400 mt-2 m-0">
                            Los perfiles fiscales se crean desde la sección de facturación del suscriptor.
                        </p>
                    </div>

                    <!-- Combined purchase history -->
                    <div v-if="allStampPurchases && allStampPurchases.length > 0" class="mt-6">
                        <h3 class="text-xs font-bold text-gray-500 m-0 mb-3">Historial de movimientos</h3>
                        <DataTable
                            :value="allStampPurchases"
                            paginator
                            :rows="10"
                            :rowsPerPageOptions="[10, 15, 25, 50]"
                            stripedRows
                            class="w-full text-sm"
                            paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                            currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} movimientos"
                            :pt="{
                                root: { class: '!bg-transparent' },
                                headerRow: { class: '!bg-transparent' },
                                paginator: { root: { class: '!bg-transparent !border-0 !pt-3' } },
                            }"
                        >
                            <Column header="Perfil">
                                <template #body="{ data }">
                                    <span class="text-xs">{{ data.fiscal_profile?.razon_social ?? '—' }}</span>
                                </template>
                            </Column>
                            <Column header="Cantidad">
                                <template #body="{ data }">
                                    {{ data.stamp_quantity.toLocaleString() }}
                                </template>
                            </Column>
                            <Column header="Método">
                                <template #body="{ data }">
                                    {{ stampPaymentMethodLabel(data.payment_method) }}
                                </template>
                            </Column>
                            <Column header="Estado">
                                <template #body="{ data }">
                                    <Tag :value="stampStatusLabel(data.status)" :severity="stampStatusSeverity(data.status)" class="!rounded-full" />
                                </template>
                            </Column>
                            <Column header="Fecha">
                                <template #body="{ data }">
                                    <span class="text-xs text-gray-500">{{ formatDate(data.created_at) }}</span>
                                </template>
                            </Column>
                        </DataTable>
                    </div>
                </div>

            </div>
        </div>

        <!-- Modal: Editar items de una versión específica -->
        <EditVersionItemsModal
            v-if="selectedVersion"
            v-model:visible="showEditVersionItemsModal"
            :version="selectedVersion"
            :plan-items="planItems"
        />

        <!-- Modal: Registrar pago con nueva versión -->
        <RegisterPaymentModal
            v-model:visible="showRegisterPaymentModal"
            :subscription-id="subscription.id"
            :plan-items="planItems"
        />

        <!-- Modal: Ajuste de timbres (manual o compra) -->
        <AdjustStampModal
            :fiscalProfile="selectedFiscalProfile"
            :visible="showStampModal"
            :showModeToggle="false"
            @update:visible="showStampModal = $event"
            @success="onStampAdjustSuccess"
        />

    </AppLayout>
</template>