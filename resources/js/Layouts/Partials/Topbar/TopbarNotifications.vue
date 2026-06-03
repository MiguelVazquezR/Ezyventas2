<script setup>
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const notifications = computed(() => page.props.notifications || { total: 0, expiring_debts: 0, upcoming_deliveries: 0 });
const activeAlertsCount = computed(() => (notifications.value.expiring_debts || 0) + (notifications.value.upcoming_deliveries || 0) + (notifications.value.pending_orders || 0));

const notificationPopover = ref(); 
const toggleNotificationPopover = (event) => notificationPopover.value.toggle(event);

// --- TESLA UI PASS-THROUGH (PT) ---
const popoverPt = {
    root: { class: 'dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a] !rounded-3xl shadow-2xl overflow-hidden mt-2' },
    content: { class: 'p-5' }
};
</script>

<template>
    <!-- Botón de Alertas Operativas -->
    <button v-if="activeAlertsCount > 0" 
        type="button" 
        class="relative mr-2 flex items-center justify-center w-10 h-10 rounded-full hover:bg-gray-100 dark:hover:bg-[#1a1a1a] transition-colors" 
        @click="toggleNotificationPopover"
        v-tooltip.bottom="'Alertas operativas'"
    >
        <i class="pi pi-bell !text-xl text-amber-500" :class="{'animate-swing': activeAlertsCount > 0}"></i>
        <!-- Indicador LED sutil en lugar del Badge enorme -->
        <span class="absolute top-2 right-2 w-2 h-2 bg-amber-500 rounded-full border border-white dark:border-[#232323]"></span>
    </button>
    
    <Popover ref="notificationPopover" :pt="popoverPt">
        <div class="w-72">
            <div class="mb-4 flex items-center gap-2">
                <span class="w-1.5 h-1.5 rounded-full bg-amber-500 shadow-[0_0_8px_rgba(245,158,11,0.8)] animate-pulse"></span>
                <h4 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Pendientes de atención</h4>
            </div>
            
            <div class="flex flex-col gap-3">
                <!-- Item: Pedidos pendientes -->
                <Link v-if="notifications.pending_orders > 0" 
                    :href="route('online-store.orders.index')" 
                    class="flex items-center justify-between p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-transparent hover:border-gray-200 dark:hover:border-[#3a3a3a] transition-all group"
                >
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-orange-50 dark:bg-orange-900/20 flex items-center justify-center flex-shrink-0 border border-orange-100 dark:border-orange-900/30">
                            <i class="pi pi-shopping-bag !text-xs text-orange-500"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100 group-hover:text-orange-600 dark:group-hover:text-orange-400 transition-colors">Pedidos por preparar</span>
                    </div>
                    <span class="text-lg font-light tracking-tight text-gray-900 dark:text-white">{{ notifications.pending_orders }}</span>
                </Link>

                <!-- Item: Vencimientos próximos -->
                <Link v-if="notifications.expiring_debts > 0" 
                    :href="route('dashboard')" 
                    class="flex items-center justify-between p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-transparent hover:border-gray-200 dark:hover:border-[#3a3a3a] transition-all group"
                >
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-purple-50 dark:bg-purple-900/20 flex items-center justify-center flex-shrink-0 border border-purple-100 dark:border-purple-900/30">
                            <i class="pi pi-clock !text-xs text-purple-500"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100 group-hover:text-purple-600 dark:group-hover:text-purple-400 transition-colors">Vencimientos</span>
                    </div>
                    <span class="text-lg font-light tracking-tight text-gray-900 dark:text-white">{{ notifications.expiring_debts }}</span>
                </Link>

                <!-- Item: Entregas -->
                <Link v-if="notifications.upcoming_deliveries > 0" 
                    :href="route('dashboard')" 
                    class="flex items-center justify-between p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-transparent hover:border-gray-200 dark:hover:border-[#3a3a3a] transition-all group"
                >
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                            <i class="pi pi-truck !text-xs text-blue-500"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100 group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">Entregas</span>
                    </div>
                    <span class="text-lg font-light tracking-tight text-gray-900 dark:text-white">{{ notifications.upcoming_deliveries }}</span>
                </Link>
            </div>
        </div>
    </Popover>
</template>