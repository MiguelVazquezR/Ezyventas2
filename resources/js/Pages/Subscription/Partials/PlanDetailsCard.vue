<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

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

const formatDate = (dateString) => new Date(dateString).toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' });
</script>

<template>
    <Card v-if="currentVersion">
        <template #title>
            <div class="flex justify-between items-center">
                <span>Plan actual y módulos</span>
                <Link :href="manageButton.route" :disabled="manageButton.disabled">
                <Button :label="manageButton.label" :icon="manageButton.icon"
                    :disabled="manageButton.disabled" size="small"
                    :severity="manageButton.severity || 'primary'" />
                </Link>
            </div>
        </template>
        <template #subtitle>
            <span v-if="!pendingPayment">
                Vigencia: {{ formatDate(currentVersion.start_date) }} - {{
                    formatDate(currentVersion.end_date) }}
            </span>
            <span v-else class="text-yellow-600">
                Esperando aprobación de pago para iniciar nuevo periodo.
            </span>
        </template>
        <template #content>
            <Message
                v-if="!subscriptionStatus.isExpired && subscriptionStatus.daysUntilExpiry !== null && subscriptionStatus.daysUntilExpiry <= 5 && !pendingPayment && !lastRejectedPayment"
                severity="warn" :closable="false" class="mb-4">
                Tu suscripción expira en {{ subscriptionStatus.daysUntilExpiry }}
                {{ subscriptionStatus.daysUntilExpiry === 1 ? 'día' : 'días' }}.
                ¡Renueva ahora para no perder acceso!
            </Message>

            <div class="mb-6">
                <h4 class="font-bold mb-4 text-gray-800 dark:text-gray-200">Módulos</h4>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    <div v-for="module in activeModules" :key="module.key"
                        class="p-4 rounded-lg text-center flex flex-col items-center justify-center transition-all"
                        :class="module.is_active ? 'bg-gray-50 dark:bg-gray-800' : 'bg-gray-100 dark:bg-gray-900 opacity-60'">
                        <div class="relative w-full">
                            <i
                                :class="[module.meta?.icon, '!text-2xl mb-2', module.is_active ? 'text-primary-500' : 'text-gray-500']"></i>
                            <i v-if="module.is_active"
                                class="pi pi-check-circle text-green-500 absolute -top-1 -right-1 bg-white dark:bg-gray-800 rounded-full"></i>
                        </div>
                        <span class="font-semibold text-sm">{{ module.name }}</span>
                    </div>
                </div>
            </div>
            <div>
                <h4 class="font-bold mb-4 text-gray-800 dark:text-gray-200">Límites del plan</h4>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div v-for="limit in activeLimits" :key="limit.item_key"
                        class="bg-gray-50 dark:bg-gray-800 p-4 rounded-lg text-center flex flex-col justify-between">
                        <div>
                            <p class="text-2xl font-bold">
                                {{ getUsage(limit) }} / <span class="text-gray-400">{{ limit.quantity
                                    === -1 ? '∞' : limit.quantity }}</span>
                            </p>
                            <p class="text-sm text-gray-500">{{ limit.name }}</p>
                        </div>
                        <ProgressBar v-if="limit.quantity > 0"
                            :value="Math.round((getUsage(limit) / limit.quantity) * 100)"
                            :showValue="false" class="h-2 mt-2"></ProgressBar>
                    </div>
                </div>
            </div>
        </template>
    </Card>
</template>