<script setup>
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const notifications = computed(() => page.props.notifications || { total: 0, expiring_debts: 0, upcoming_deliveries: 0 });
const activeAlertsCount = computed(() => (notifications.value.expiring_debts || 0) + (notifications.value.upcoming_deliveries || 0));

const notificationPopover = ref(); 
const toggleNotificationPopover = (event) => notificationPopover.value.toggle(event);
</script>

<template>
    <!-- Botón de Alertas Operativas -->
    <button v-if="activeAlertsCount > 0" 
        type="button" 
        class="layout-topbar-action relative mr-2" 
        @click="toggleNotificationPopover"
        v-tooltip.bottom="'Alertas operativas'"
    >
        <i class="pi pi-bell text-xl text-amber-500" :class="{'animate-swing': activeAlertsCount > 0}"></i>
    </button>
    
    <Popover ref="notificationPopover">
        <div class="w-64">
            <h4 class="font-bold text-surface-700 dark:text-surface-200 mb-2 px-2 text-sm">Pendientes de atención</h4>
            <div class="flex flex-col gap-1">
                <!-- Item: Vencimientos próximos -->
                <Link v-if="notifications.expiring_debts > 0" 
                    :href="route('dashboard')" 
                    class="flex items-center justify-between p-2 rounded-lg hover:bg-purple-50 dark:hover:bg-purple-900/20 text-purple-700 dark:text-purple-300 transition-colors"
                >
                    <div class="flex items-center gap-2">
                        <i class="pi pi-clock"></i>
                        <span class="text-sm font-medium">Vencimientos próximos</span>
                    </div>
                    <Badge :value="notifications.expiring_debts" class="!bg-purple-500" />
                </Link>

                <!-- Item: Entregas -->
                <Link v-if="notifications.upcoming_deliveries > 0" 
                    :href="route('dashboard')" 
                    class="flex items-center justify-between p-2 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/20 text-blue-700 dark:text-blue-300 transition-colors"
                >
                    <div class="flex items-center gap-2">
                        <i class="pi pi-truck"></i>
                        <span class="text-sm font-medium">Próximas entregas</span>
                    </div>
                    <Badge :value="notifications.upcoming_deliveries" severity="info" />
                </Link>
            </div>
        </div>
    </Popover>
</template>