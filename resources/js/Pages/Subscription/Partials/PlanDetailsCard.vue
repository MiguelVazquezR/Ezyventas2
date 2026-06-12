<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Button from 'primevue/button';
import ProgressBar from 'primevue/progressbar';

const props = defineProps({
    currentVersion: Object,
    planItems: Array,
    usageData: Object,
    activeLimits: Array,
    subscriptionStatus: Object,
    pendingPayment: Object,
    lastRejectedPayment: Object
});

const manageButton = computed(() => {
    if (props.pendingPayment) {
        return { label: 'Pago en revisión', icon: 'pi pi-clock', route: '#', disabled: true };
    }
    if (props.lastRejectedPayment) {
        return { label: 'Reintentar pago', icon: 'pi pi-exclamation-triangle', route: route('subscription.manage'), disabled: false, severity: 'danger' };
    }
    const isRenewalTime = props.subscriptionStatus.isExpired || (props.subscriptionStatus.daysUntilExpiry !== null && props.subscriptionStatus.daysUntilExpiry <= 5);
    if (isRenewalTime) {
        return { label: 'Renovar suscripción', icon: 'pi pi-refresh', route: route('subscription.manage'), disabled: false, severity: 'primary' };
    }
    return { label: 'Mejorar suscripción', icon: 'pi pi-arrow-up', route: route('subscription.manage'), disabled: false, severity: 'secondary' };
});

const displayPlanItems = computed(() => {
    if (!props.currentVersion) return [];
    const activeItemKeys = new Set(props.currentVersion.items.map(item => item.item_key));
    return props.planItems.map(planItem => ({
        ...planItem,
        is_active: activeItemKeys.has(planItem.key),
    }));
});

const activeModules = computed(() => displayPlanItems.value.filter(item => item.type === 'module'));

const getUsage = (limit) => {
    if (!props.usageData || !limit.item_key) return 0;
    const resourceKey = limit.item_key.replace('limit_', '');
    return props.usageData[resourceKey] ?? 0;
};

const formatDate = (dateString) => new Date(dateString).toLocaleDateString('es-MX', { year: 'numeric', month: 'short', day: 'numeric' });

// --- TESLA UI PASS-THROUGH (PT) ---
const progressBarPt = {
    root: { class: '!h-1.5 !bg-gray-200 dark:!bg-[#2a2a2a] !rounded-full overflow-hidden mt-3' },
    value: { class: '!bg-blue-500' }
};
</script>

<template>
    <div v-if="currentVersion" class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
        
        <!-- Header -->
        <div class="mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-purple-50 dark:bg-purple-900/20 flex items-center justify-center flex-shrink-0 border border-purple-100 dark:border-purple-900/30">
                    <i class="pi pi-star !text-sm text-purple-500"></i>
                </div>
                <div>
                    <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Plan actual y módulos</h2>
                    <p v-if="!pendingPayment" class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">
                        Vigencia: {{ formatDate(currentVersion.start_date) }} - {{ formatDate(currentVersion.end_date) }}
                    </p>
                    <p v-else class="text-[10px] text-yellow-600 dark:text-yellow-500 uppercase tracking-widest mt-1 m-0 flex items-center gap-1.5">
                        <i class="pi pi-spin pi-spinner !text-[9px]"></i> Esperando aprobación de pago
                    </p>
                </div>
            </div>

            <Link :href="manageButton.route" :disabled="manageButton.disabled">
                <Button :label="manageButton.label" :icon="manageButton.icon" :disabled="manageButton.disabled" 
                    :severity="manageButton.severity || 'primary'" 
                    class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold w-full sm:w-auto" 
                    :class="{'shadow-sm': !manageButton.disabled}" />
            </Link>
        </div>

        <!-- Alerta de Expiración -->
        <div v-if="!subscriptionStatus.isExpired && subscriptionStatus.daysUntilExpiry !== null && subscriptionStatus.daysUntilExpiry <= 5 && !pendingPayment && !lastRejectedPayment"
            class="mb-6 bg-orange-50 dark:bg-orange-900/10 border border-orange-200 dark:border-orange-800/50 p-4 rounded-2xl flex items-center gap-3">
            <i class="pi pi-exclamation-triangle text-orange-500 !text-lg"></i>
            <div>
                <p class="font-bold text-sm text-orange-800 dark:text-orange-400 m-0">Tu suscripción está por terminar</p>
                <p class="text-xs text-orange-700 dark:text-orange-300/80 m-0 mt-0.5">Expira en {{ subscriptionStatus.daysUntilExpiry }} {{ subscriptionStatus.daysUntilExpiry === 1 ? 'día' : 'días' }}. ¡Renueva ahora para no perder acceso!</p>
            </div>
        </div>

        <!-- Módulos Contratados -->
        <div class="mb-8">
            <h3 class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 m-0 mb-4">Módulos del sistema</h3>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                <div v-for="module in activeModules" :key="module.key"
                    class="p-4 rounded-2xl text-center flex flex-col items-center justify-center transition-all border"
                    :class="module.is_active ? 'bg-primary-50 dark:bg-primary-900/10 border-primary-100 dark:border-primary-800/30 shadow-sm' : 'bg-gray-50 dark:bg-[#1a1a1a] border-gray-100 dark:border-[#3a3a3a] opacity-60'">
                    
                    <div class="relative w-full flex justify-center mb-3">
                        <i :class="[module.meta?.icon, '!text-2xl transition-colors', module.is_active ? 'text-primary-500' : 'text-gray-400 dark:text-gray-600']"></i>
                        <div v-if="module.is_active" class="absolute -top-1 right-1/4 translate-x-3 bg-white dark:bg-[#232323] rounded-full flex items-center justify-center p-0.5">
                            <i class="pi pi-check-circle text-green-500 !text-xs"></i>
                        </div>
                    </div>
                    
                    <span class="text-xs font-medium tracking-tight m-0" :class="module.is_active ? 'text-primary-900 dark:text-primary-100' : 'text-gray-500'">
                        {{ module.name }}
                    </span>
                </div>
            </div>
        </div>

        <!-- Límites del Plan -->
        <div class="pt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
            <h3 class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 m-0 mb-4">Límites de uso</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
                <div v-for="limit in activeLimits" :key="limit.item_key"
                    class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col justify-between">
                    
                    <div class="flex justify-between items-start mb-2">
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">{{ limit.name }}</span>
                    </div>
                    
                    <div class="flex items-baseline gap-1 mt-1">
                        <span class="text-3xl font-light tracking-tight text-gray-900 dark:text-white leading-none">{{ getUsage(limit) }}</span>
                        <span class="text-sm font-medium text-gray-400 dark:text-gray-600">/ {{ limit.quantity === -1 ? '∞' : limit.quantity }}</span>
                    </div>
                    
                    <ProgressBar v-if="limit.quantity > 0" :value="Math.round((getUsage(limit) / limit.quantity) * 100)" :showValue="false" :pt="progressBarPt" />
                </div>
            </div>
        </div>

    </div>
</template>