<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { MODULE_SHORT_DESCRIPTIONS } from '@/constants/modules';
import { useSupportWhatsApp } from '@/Composables';

const props = defineProps({
    currentVersion: Object,
    planItems: Array,
    usageData: Object,
    activeLimits: Array,
    subscriptionStatus: Object,
    pendingPayment: Object,
    lastRejectedPayment: Object,
    plan: Object
});

const { whatsAppUrl } = useSupportWhatsApp();

const increaseLimitsUrl = computed(() => whatsAppUrl(
    'Hola, necesito aumentar los límites de uso de mi cuenta en EzyVentas.'
));

// The free plan never expires: there is nothing to renew, only modules to toggle.
const isOpenEnded = computed(() => props.plan?.is_open_ended === true && !props.pendingPayment && !props.lastRejectedPayment);

const planModules = computed(() => {
    if (!props.currentVersion) return [];

    const activeItemKeys = new Set(props.currentVersion.items.map(item => item.item_key));

    return props.planItems
        .filter(planItem => planItem.type === 'module')
        .map(planItem => ({
            ...planItem,
            is_active: activeItemKeys.has(planItem.key),
        }));
});

const processingModuleKey = ref(null);

const toggleModule = (module, isActive) => {
    processingModuleKey.value = module.key;

    router.patch(route('subscription.modules.toggle'), {
        module_key: module.key,
        is_active: isActive,
    }, {
        preserveScroll: true,
        onFinish: () => {
            processingModuleKey.value = null;
        },
    });
};

const moduleDescription = (module) => MODULE_SHORT_DESCRIPTIONS[module.key] || module.description || '';

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
        <div class="mb-6 flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-purple-50 dark:bg-purple-900/20 flex items-center justify-center flex-shrink-0 border border-purple-100 dark:border-purple-900/30">
                <i class="pi pi-star !text-sm text-purple-500"></i>
            </div>
            <div>
                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Plan actual y módulos</h2>
                <p v-if="pendingPayment" class="text-[10px] text-yellow-600 dark:text-yellow-500 uppercase tracking-widest mt-1 m-0 flex items-center gap-1.5">
                    <i class="pi pi-spin pi-spinner !text-[9px]"></i> Esperando aprobación de pago
                </p>
                <p v-else-if="isOpenEnded" class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0 flex items-center gap-1.5">
                    <i class="pi pi-infinity !text-[9px]"></i>
                    Vigencia permanente · sin fecha de vencimiento
                </p>
                <p v-else class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">
                    Vigencia: {{ formatDate(currentVersion.start_date) }} - {{ formatDate(currentVersion.end_date) }}
                </p>
            </div>
        </div>

        <!-- Alerta de Expiración -->
        <div v-if="!isOpenEnded && !subscriptionStatus.isExpired && subscriptionStatus.daysUntilExpiry !== null && subscriptionStatus.daysUntilExpiry <= 5 && !pendingPayment && !lastRejectedPayment"
            class="mb-6 bg-orange-50 dark:bg-orange-900/10 border border-orange-200 dark:border-orange-800/50 p-4 rounded-2xl flex items-center gap-3">
            <i class="pi pi-exclamation-triangle text-orange-500 !text-lg"></i>
            <div>
                <p class="font-bold text-sm text-orange-800 dark:text-orange-400 m-0">Tu cuenta está por terminar</p>
                <p class="text-xs text-orange-700 dark:text-orange-300/80 m-0 mt-0.5">Expira en {{ subscriptionStatus.daysUntilExpiry }} {{ subscriptionStatus.daysUntilExpiry === 1 ? 'día' : 'días' }}. Contacta a soporte para conservar tu acceso.</p>
            </div>
        </div>

        <!-- Módulos Contratados -->
        <div class="mb-8">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1 mb-4">
                <h3 class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 m-0">Módulos del sistema</h3>
                <span class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-600 m-0">Activa solo los que necesites</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
                <div v-for="module in planModules" :key="module.key"
                    class="p-4 rounded-2xl flex flex-col gap-3 transition-all border"
                    :class="module.is_active ? 'bg-primary-50 dark:bg-primary-900/10 border-primary-100 dark:border-primary-800/30 shadow-sm' : 'bg-gray-50 dark:bg-[#1a1a1a] border-gray-100 dark:border-[#3a3a3a]'">

                    <div class="flex items-start justify-between gap-3">
                        <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 border"
                            :class="module.is_active ? 'bg-white dark:bg-[#232323] border-primary-100 dark:border-primary-800/30' : 'bg-white dark:bg-[#232323] border-gray-100 dark:border-[#3a3a3a]'">
                            <i :class="[module.meta?.icon || 'pi pi-box', '!text-base', module.is_active ? 'text-primary-500' : 'text-gray-400 dark:text-gray-600']"></i>
                        </div>

                        <ToggleSwitch
                            :model-value="module.is_active"
                            :disabled="processingModuleKey !== null"
                            :aria-label="`${module.is_active ? 'Desactivar' : 'Activar'} ${module.name}`"
                            class="flex-shrink-0"
                            @update:model-value="toggleModule(module, $event)"
                        />
                    </div>

                    <div class="flex-1">
                        <p class="text-xs font-bold tracking-tight m-0" :class="module.is_active ? 'text-primary-900 dark:text-primary-100' : 'text-gray-700 dark:text-gray-300'">
                            {{ module.name }}
                        </p>
                        <p class="text-[11px] leading-snug text-gray-500 dark:text-gray-400 m-0 mt-1 line-clamp-2">
                            {{ moduleDescription(module) }}
                        </p>
                    </div>

                    <div class="flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full" :class="module.is_active ? 'bg-green-500 animate-pulse' : 'bg-gray-300 dark:bg-gray-600'"></span>
                        <span class="text-[9px] uppercase tracking-widest font-bold" :class="module.is_active ? 'text-green-600 dark:text-green-400' : 'text-gray-400 dark:text-gray-500'">
                            {{ module.is_active ? 'Activo' : 'Inactivo' }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Límites del Plan -->
        <div class="pt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                <h3 class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 m-0">Límites de uso</h3>
                <a v-if="increaseLimitsUrl" :href="increaseLimitsUrl" target="_blank" rel="noopener noreferrer" class="w-full sm:w-auto">
                    <Button
                        label="Aumentar límites"
                        icon="pi pi-whatsapp"
                        severity="success"
                        outlined
                        class="!rounded-full !uppercase !tracking-widest !text-[10px] !font-bold w-full sm:w-auto"
                        v-tooltip.bottom="'Solicita el aumento por WhatsApp'"
                    />
                </a>
            </div>
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