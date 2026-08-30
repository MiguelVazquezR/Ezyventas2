<script setup>
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import axios from 'axios'; 
import AppLayout from '@/Layouts/AppLayout.vue';
import BankAccountHistoryModal from '@/Components/BankAccountHistoryModal.vue';
import BankAccountTransferModal from '@/Components/BankAccountTransferModal.vue';
import BannerOverlay from '@/Components/BannerOverlay.vue';
import { usePermissions } from '@/Composables'; 

const props = defineProps({
    stats: Object,
    userBankAccounts: Array,
    allSubscriptionBankAccounts: Array,
    activeBanner: {
        type: Object,
        default: null,
    },
});

const { hasPermission } = usePermissions();

// --- Estado del banner ---
const showBanner = ref(!!props.activeBanner);

const onBannerDismissed = () => {
    showBanner.value = false;
};

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);

const salesChange = computed(() => {
    if (props.stats.today_sales === undefined || props.stats.yesterday_sales === undefined) return null;
    if (props.stats.yesterday_sales === 0) return { value: props.stats.today_sales > 0 ? 100 : 0, sign: '+' };

    const change = ((props.stats.today_sales - props.stats.yesterday_sales) / props.stats.yesterday_sales) * 100;
    return {
        value: Math.abs(change).toFixed(1),
        sign: change >= 0 ? '+' : '-',
    };
});

const maxWeeklySale = computed(() => Math.max(...(props.stats.weekly_sales_trend?.map(d => d.total) || [1])));
const hasStatsToShow = computed(() => Object.keys(props.stats).length > 0);

const getInitials = (name) => {
    if (!name) return '?';
    const words = name.split(' ');
    if (words.length > 1) {
        return words[0][0] + words[1][0];
    }
    return name.substring(0, 2);
};

const inventoryPercentages = computed(() => {
    const summary = props.stats.inventory_summary;
    if (!summary || summary.total_products === 0) return { inStock: 100, lowStock: 0, outOfStock: 0 };
    return {
        inStock: (summary.in_stock_count / summary.total_products) * 100,
        lowStock: (summary.low_stock_count / summary.total_products) * 100,
        outOfStock: (summary.out_of_stock_count / summary.total_products) * 100,
    };
});

const getServiceOrderStatus = (status) => props.stats.service_orders_status?.[status] || 0;

// --- Lógica para Panel de Cuentas Bancarias ---
const menu = ref();
const isHistoryModalVisible = ref(false);
const isTransferModalVisible = ref(false);
const selectedAccount = ref(null);

const totalBalance = computed(() => {
    if (!props.userBankAccounts) return 0;
    return props.userBankAccounts.reduce((sum, account) => sum + parseFloat(account.balance), 0);
});

const toggleMenu = (event, account) => {
    selectedAccount.value = account;
    menu.value.toggle(event);
};

const menuItems = ref([
    { label: 'Ver Historial', icon: 'pi pi-history', command: () => { isHistoryModalVisible.value = true; } },
    { label: 'Realizar Transferencia', icon: 'pi pi-arrows-h', command: () => { isTransferModalVisible.value = true; } }
]);

const onTransferSuccess = () => {
    isTransferModalVisible.value = false;
    router.reload({ preserveState: false });
};

// --- Lógica de Modales de Alerta (Unificado) ---
const isInfoModalVisible = ref(false);
const isLoadingModal = ref(false);
const modalItems = ref([]);
const activeModalType = ref('layaways'); // 'layaways' | 'deliveries'

// --- Filtros dentro del modal de vencimientos (tipo y cliente) ---
const modalFilter = ref('todos');
const modalFilterOptions = [
    { label: 'Todos', value: 'todos' },
    { label: 'Créditos', value: 'credito' },
    { label: 'Apartados', value: 'apartado' },
];

const customerFilter = ref(null);

// Solo los clientes que aparecen en la lista actual de vencimientos
const customerFilterOptions = computed(() => {
    const seen = new Map();
    modalItems.value.forEach((item) => {
        const value = item.customer_id ?? 'general';
        if (!seen.has(value)) {
            seen.set(value, { label: item.customer_name || 'Público en General', value });
        }
    });
    return Array.from(seen.values()).sort((a, b) => a.label.localeCompare(b.label, 'es'));
});

const filteredModalItems = computed(() => {
    // Los filtros solo aplican al modal de vencimientos; las entregas siempre muestran todo.
    if (activeModalType.value !== 'layaways') return modalItems.value;

    let items = modalItems.value;

    if (modalFilter.value !== 'todos') {
        items = items.filter((item) => item.type === modalFilter.value);
    }

    if (customerFilter.value) {
        items = items.filter((item) => (item.customer_id ?? 'general') === customerFilter.value);
    }

    return items;
});

const modalTitle = computed(() => {
    return activeModalType.value === 'layaways' 
        ? 'Vencimientos próximos (Apartados y Créditos)' 
        : 'Próximas entregas de pedidos';
});

const fetchExpiringLayaways = async () => {
    activeModalType.value = 'layaways';
    modalFilter.value = 'todos';
    customerFilter.value = null;
    isInfoModalVisible.value = true;
    isLoadingModal.value = true;
    modalItems.value = [];
    
    try {
        const response = await axios.get(route('dashboard.expiring-layaways'));
        modalItems.value = response.data;
    } catch (error) {
        console.error("Error cargando apartados:", error);
    } finally {
        isLoadingModal.value = false;
    }
};

const fetchUpcomingDeliveries = async () => {
    activeModalType.value = 'deliveries';
    modalFilter.value = 'todos';
    customerFilter.value = null;
    isInfoModalVisible.value = true;
    isLoadingModal.value = true;
    modalItems.value = [];
    
    try {
        const response = await axios.get(route('dashboard.upcoming-deliveries'));
        modalItems.value = response.data;
    } catch (error) {
        console.error("Error cargando pedidos:", error);
    } finally {
        isLoadingModal.value = false;
    }
};

const getExpirationSeverity = (days) => {
    if (days < 0) return 'danger'; 
    if (days <= 2) return 'danger'; 
    return 'warning'; 
};

</script>

<template>
    <AppLayout title="Dashboard">

        <!-- Banner Overlay invasivo -->
        <BannerOverlay 
            v-if="showBanner && activeBanner" 
            :banner="activeBanner" 
            @dismissed="onBannerDismissed" 
        />

        <div class="p-4 md:p-6 lg:p-8 space-y-6 max-w-[1600px] mx-auto">
            
            <!-- Encabezado estilo Dashboard Automotriz -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4 mb-8">
                <div>
                    <h1 class="text-4xl md:text-5xl font-light tracking-tight text-gray-900 dark:text-white">Resumen</h1>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400 mt-2 tracking-wide uppercase">
                        Hola, {{ $page.props.auth.user.name }} <span class="mx-2">•</span> Modo Operativo
                    </p>
                </div>
                <div v-if="hasStatsToShow" class="flex items-center gap-2 bg-white dark:bg-[#232323] px-4 py-2 rounded-full border border-gray-200 dark:border-[#3a3a3a] shadow-sm">
                    <span class="w-2 h-2 rounded-full bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.8)] animate-pulse"></span>
                    <span class="text-xs font-semibold text-gray-600 dark:text-gray-300 tracking-wider">SISTEMA EN LÍNEA</span>
                </div>
            </div>

            <div v-if="hasStatsToShow" class="space-y-6">
                <!-- Fila 1: KPIs Principales (Diseño de Módulos Telemétricos) -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    
                    <!-- Ventas de Hoy -->
                    <Link v-if="stats.today_sales !== undefined && hasPermission('dashboard.see_sales')" :href="route('transactions.index')"
                        class="relative bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] hover:border-green-500/50 transition-all duration-300 group overflow-hidden">
                        <div class="absolute -right-6 -top-6 w-24 h-24 bg-green-500/10 rounded-full blur-2xl group-hover:bg-green-500/20 transition-all"></div>
                        <div class="flex justify-between items-start relative z-10">
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase mb-1">Ventas de hoy</h2>
                                <p class="text-4xl md:text-5xl font-light tracking-tighter text-gray-900 dark:text-white mt-2">
                                    {{ formatCurrency(stats.today_sales).split('.')[0] }}<span class="text-2xl text-gray-400">.{{ formatCurrency(stats.today_sales).split('.')[1] }}</span>
                                </p>
                            </div>
                            <i class="pi pi-dollar text-green-500 !text-xl"></i>
                        </div>
                        <div v-if="salesChange" class="flex items-center gap-2 mt-4 pt-4 border-t border-gray-100 dark:border-[#3a3a3a] relative z-10">
                            <span class="w-1.5 h-1.5 rounded-full" :class="salesChange.sign === '+' ? 'bg-green-500 shadow-[0_0_5px_rgba(34,197,94,0.8)]' : 'bg-red-500 shadow-[0_0_5px_rgba(239,68,68,0.8)]'"></span>
                            <span class="text-xs font-medium" :class="salesChange.sign === '+' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                                <span v-if="salesChange.value > 0">{{ salesChange.sign }}{{ salesChange.value }}% vs ayer</span>
                                <span v-else class="text-gray-500 dark:text-gray-400">Sin cambios</span>
                            </span>
                        </div>
                    </Link>
                    
                    <!-- Apartados y Créditos -->
                    <div v-if="stats.expiring_debts_count !== undefined && hasPermission('dashboard.see_layaways')" 
                        @click="fetchExpiringLayaways"
                        class="relative bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] hover:border-purple-500/50 transition-all duration-300 cursor-pointer group overflow-hidden">
                        <div class="absolute -right-6 -top-6 w-24 h-24 bg-purple-500/10 rounded-full blur-2xl group-hover:bg-purple-500/20 transition-all"></div>
                        <div class="flex justify-between items-start relative z-10">
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase mb-1">Vencimientos</h2>
                                <p class="text-4xl md:text-5xl font-light tracking-tighter text-gray-900 dark:text-white mt-2">
                                    {{ stats.expiring_debts_count }}
                                </p>
                            </div>
                            <i class="pi pi-clock text-purple-500 !text-xl" :class="{ 'animate-pulse': stats.expiring_debts_count > 0 }"></i>
                        </div>
                        <div class="mt-4 pt-4 border-t border-gray-100 dark:border-[#3a3a3a] relative z-10">
                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400 flex items-center gap-2">
                                <i class="pi pi-info-circle !text-[10px]"></i> Apartados y créditos próximos
                            </span>
                        </div>
                    </div>

                    <!-- Próximas Entregas -->
                    <div v-if="stats.upcoming_deliveries_count !== undefined && hasPermission('dashboard.see_orders')" 
                        @click="fetchUpcomingDeliveries"
                        class="relative bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] hover:border-blue-500/50 transition-all duration-300 cursor-pointer group overflow-hidden">
                        <div class="absolute -right-6 -top-6 w-24 h-24 bg-blue-500/10 rounded-full blur-2xl group-hover:bg-blue-500/20 transition-all"></div>
                        <div class="flex justify-between items-start relative z-10">
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase mb-1">Entregas</h2>
                                <p class="text-4xl md:text-5xl font-light tracking-tighter text-gray-900 dark:text-white mt-2">
                                    {{ stats.upcoming_deliveries_count }}
                                </p>
                            </div>
                            <i class="pi pi-truck text-blue-500 !text-xl" :class="{ 'animate-pulse': stats.upcoming_deliveries_count > 0 }"></i>
                        </div>
                        <div class="mt-4 pt-4 border-t border-gray-100 dark:border-[#3a3a3a] relative z-10">
                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400 flex items-center gap-2">
                                <i class="pi pi-box !text-[10px]"></i> Pendientes de envío
                            </span>
                        </div>
                    </div>

                    <!-- Saldo por Cobrar -->
                    <Link v-if="stats.total_customer_debt !== undefined && hasPermission('dashboard.see_outstanding_balances')" :href="route('customers.index')"
                        class="relative bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] hover:border-cyan-500/50 transition-all duration-300 group overflow-hidden">
                        <div class="absolute -right-6 -top-6 w-24 h-24 bg-cyan-500/10 rounded-full blur-2xl group-hover:bg-cyan-500/20 transition-all"></div>
                        <div class="flex justify-between items-start relative z-10">
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase mb-1">Por Cobrar</h2>
                                <p class="text-3xl md:text-4xl font-light tracking-tighter text-gray-900 dark:text-white mt-2">
                                    {{ formatCurrency(stats.total_customer_debt).split('.')[0] }}<span class="text-xl text-gray-400">.{{ formatCurrency(stats.total_customer_debt).split('.')[1] }}</span>
                                </p>
                            </div>
                            <i class="pi pi-credit-card text-cyan-500 !text-xl"></i>
                        </div>
                        <div class="mt-4 pt-4 border-t border-gray-100 dark:border-[#3a3a3a] relative z-10">
                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400 flex items-center gap-2">
                                <i class="pi pi-users !text-[10px]"></i> Deuda total de clientes
                            </span>
                        </div>
                    </Link>
                </div>

                <!-- Fila 2: Gráficos y Estado de Módulos -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-6">
                    <!-- Tendencia de Ventas (Estilo Ecualizador / Data Visualizer) -->
                    <div v-if="stats.weekly_sales_trend && hasPermission('dashboard.see_sales')"
                        class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] col-span-1 lg:col-span-2 min-h-[250px] flex flex-col">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase">Tendencia Semanal</h2>
                            <i class="pi pi-chart-bar text-gray-400"></i>
                        </div>
                        <div class="flex justify-between items-end flex-grow h-full gap-2 md:gap-4 px-2">
                            <div v-for="day in stats.weekly_sales_trend" :key="day.day"
                                class="text-center w-full group flex flex-col items-center justify-end h-[150px]">
                                <div v-tooltip.top="formatCurrency(day.total)"
                                    class="bg-primary-500/80 dark:bg-primary-500/60 w-full max-w-[40px] rounded-t-lg mx-auto transition-all duration-500 group-hover:bg-primary-500 group-hover:shadow-[0_0_15px_rgba(246,140,15,0.5)] cursor-pointer relative"
                                    :style="{ height: `${(day.total / maxWeeklySale) * 100}%`, minHeight: '4px' }">
                                    <div class="absolute -top-6 left-1/2 -translate-x-1/2 opacity-0 group-hover:opacity-100 transition-opacity text-[10px] font-bold text-gray-700 dark:text-gray-200 pointer-events-none">
                                        {{ formatCurrency(day.total).split('.')[0] }}
                                    </div>
                                </div>
                                <p class="text-xs mt-3 font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ day.day.substring(0,3) }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Módulos de Estado (Cajas y Órdenes) -->
                    <div class="space-y-6 flex flex-col">
                        <Link v-if="stats.cash_registers_status" :href="route('cash-registers.index')"
                            class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] hover:border-gray-300 dark:hover:border-gray-600 transition-all flex-1 flex flex-col justify-between">
                            <div class="flex justify-between items-center mb-4">
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase">Cajas Registradoras</h2>
                                <i class="pi pi-desktop text-gray-400"></i>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div class="bg-gray-50 dark:bg-[#1a1a1a] p-4 rounded-2xl text-center">
                                    <div class="flex items-center justify-center gap-2 mb-1">
                                        <span class="w-1.5 h-1.5 rounded-full bg-green-500 shadow-[0_0_5px_rgba(34,197,94,0.8)]"></span>
                                        <span class="text-xs text-gray-500 uppercase tracking-wider">Activas</span>
                                    </div>
                                    <span class="text-3xl font-light text-gray-900 dark:text-white">{{ stats.cash_registers_status.in_use_count || 0 }}</span>
                                </div>
                                <div class="bg-gray-50 dark:bg-[#1a1a1a] p-4 rounded-2xl text-center">
                                    <div class="flex items-center justify-center gap-2 mb-1">
                                        <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                                        <span class="text-xs text-gray-500 uppercase tracking-wider">Libres</span>
                                    </div>
                                    <span class="text-3xl font-light text-gray-900 dark:text-white">{{ stats.cash_registers_status.available_count || 0 }}</span>
                                </div>
                            </div>
                        </Link>

                        <Link v-if="stats.service_orders_status" :href="route('service-orders.index')"
                            class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] hover:border-gray-300 dark:hover:border-gray-600 transition-all flex-1 flex flex-col justify-between">
                            <div class="flex justify-between items-center mb-4">
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase">Órdenes Servicio</h2>
                                <i class="pi pi-wrench text-gray-400"></i>
                            </div>
                            <div class="grid grid-cols-4 gap-2 text-center">
                                <div>
                                    <p class="text-2xl font-light text-gray-900 dark:text-white">{{ getServiceOrderStatus('pendiente') }}</p>
                                    <p class="text-[10px] text-gray-500 uppercase mt-1">Pend</p>
                                </div>
                                <div>
                                    <p class="text-2xl font-light text-blue-500">{{ getServiceOrderStatus('en_proceso') }}</p>
                                    <p class="text-[10px] text-gray-500 uppercase mt-1">Proc</p>
                                </div>
                                <div>
                                    <p class="text-2xl font-light text-purple-500">{{ getServiceOrderStatus('completado') }}</p>
                                    <p class="text-[10px] text-gray-500 uppercase mt-1">Lista</p>
                                </div>
                                <div>
                                    <p class="text-2xl font-light text-green-500">{{ getServiceOrderStatus('entregado') }}</p>
                                    <p class="text-[10px] text-gray-500 uppercase mt-1">Entr</p>
                                </div>
                            </div>
                        </Link>
                    </div>
                </div>

                <!-- Cuentas Bancarias (Estilo Billetera Digital) -->
                <div v-if="userBankAccounts" class="mt-6">
                    <div class="bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] p-6 lg:p-8">
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
                            <div>
                                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase">Tesorería</h2>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Gestión de cuentas y liquidez</p>
                            </div>
                            <div class="mt-4 md:mt-0 text-right">
                                <p class="text-xs text-gray-500 uppercase tracking-widest mb-1">Balance Total</p>
                                <p class="text-3xl font-light tracking-tight text-gray-900 dark:text-white">{{ formatCurrency(totalBalance) }}</p>
                            </div>
                        </div>

                        <div v-if="userBankAccounts.length > 0" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                            <div v-for="account in userBankAccounts" :key="account.id"
                                class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl flex justify-between items-center group hover:bg-gray-100 dark:hover:bg-[#2a2a2a] transition-colors border border-transparent hover:border-gray-200 dark:hover:border-gray-700">
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 rounded-full bg-primary-50 dark:bg-primary-900/30 flex items-center justify-center text-primary-500">
                                        <i class="pi pi-building !text-lg"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium text-gray-900 dark:text-gray-100 m-0">{{ account.account_name }}</p>
                                        <p class="text-xs text-gray-500 m-0">{{ account.bank_name }}</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="font-mono text-lg font-light text-gray-900 dark:text-white">{{ formatCurrency(account.balance) }}</span>
                                    <Button icon="pi pi-ellipsis-v" text rounded severity="secondary" @click="toggleMenu($event, account)" class="!w-8 !h-8" />
                                </div>
                            </div>
                            <Menu ref="menu" :model="menuItems" :popup="true" class="!rounded-xl !border-gray-200 dark:!border-[#3a3a3a] dark:!bg-[#232323]" />
                        </div>
                        <div v-else class="text-center py-10 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl">
                            <i class="pi pi-wallet !text-3xl text-gray-400 mb-3"></i>
                            <p class="text-gray-500 text-sm">No hay cuentas bancarias vinculadas.</p>
                        </div>
                    </div>
                </div>

                <!-- Fila 3: Productos (Top y Baja Rotación) -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
                    <!-- Top Productos -->
                    <div v-if="stats.top_selling_products && stats.top_selling_products.length > 0"
                        class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase">Top 5 Productos (Mes)</h2>
                            <i class="pi pi-star-fill text-yellow-500 !text-sm"></i>
                        </div>
                        <ul class="space-y-2">
                            <li v-for="(product, index) in stats.top_selling_products" :key="`${product.id}-${index}`">
                                <Link :href="route('products.show', product.id)"
                                    class="flex items-center gap-4 hover:bg-gray-50 dark:hover:bg-[#1a1a1a] p-3 rounded-2xl transition-colors group">
                                    <div class="relative">
                                        <img v-if="product.image" :src="product.image" :alt="product.name"
                                            class="w-12 h-12 rounded-xl object-cover shadow-sm group-hover:shadow-md transition-shadow">
                                        <Avatar v-else :label="getInitials(product.name)" shape="circle" size="large" class="!rounded-xl !bg-gray-100 dark:!bg-[#3a3a3a] !text-gray-600 dark:!text-gray-300" />
                                        <div class="absolute -top-2 -right-2 w-5 h-5 bg-gray-900 dark:bg-white text-white dark:text-gray-900 rounded-full flex items-center justify-center text-[10px] font-bold shadow-sm">
                                            {{ index + 1 }}
                                        </div>
                                    </div>
                                    <div class="flex-grow">
                                        <p class="font-medium text-sm text-gray-900 dark:text-gray-100">{{ product.name }}</p>
                                        <p v-if="product.variant_description" class="text-[10px] text-primary-500 font-medium uppercase mt-0.5">
                                            {{ product.variant_description }}
                                        </p>
                                        <p class="text-xs text-gray-500 mt-0.5">{{ product.total_sold }} unidades</p>
                                    </div>
                                    <p class="font-light text-lg text-gray-900 dark:text-white">{{ formatCurrency(product.selling_price) }}</p>
                                </Link>
                            </li>
                        </ul>
                        <Link :href="route('products.index')" class="block mt-4">
                            <Button label="Ver catálogo completo" severity="secondary" text class="w-full !rounded-xl !text-xs !uppercase !tracking-wider" />
                        </Link>
                    </div>

                    <!-- Baja Rotación -->
                    <div v-if="stats.low_turnover_products"
                        class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
                        <div class="flex justify-between items-center mb-2">
                            <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase">Baja Rotación</h2>
                            <i class="pi pi-exclamation-triangle text-orange-500 !text-sm"></i>
                        </div>
                        <p class="text-[11px] text-gray-400 mb-4">+15 días sin movimiento</p>
                        <ul v-if="stats.low_turnover_products.length > 0" class="space-y-2 flex-grow">
                            <li v-for="product in stats.low_turnover_products" :key="product.id">
                                <Link :href="route('products.show', product.id)"
                                    class="flex items-center gap-4 hover:bg-gray-50 dark:hover:bg-[#1a1a1a] p-3 rounded-2xl transition-colors">
                                    <img v-if="product.image" :src="product.image" :alt="product.name"
                                        class="w-10 h-10 rounded-xl object-cover grayscale opacity-70">
                                    <Avatar v-else :label="getInitials(product.name)" shape="circle" class="!rounded-xl !bg-gray-100 dark:!bg-[#3a3a3a] !text-gray-500" />
                                    <div class="flex-grow overflow-hidden">
                                        <p class="font-medium text-sm text-gray-900 dark:text-gray-300 truncate">{{ product.name }}</p>
                                        <div class="flex items-center gap-2 mt-1">
                                            <span class="text-[10px] bg-gray-100 dark:bg-[#3a3a3a] text-gray-600 dark:text-gray-400 px-2 py-0.5 rounded-full">
                                                Stock: {{ product.current_stock }}
                                            </span>
                                            <span class="text-[10px] text-red-500/80 font-medium">
                                                <i class="pi pi-clock !text-[9px] mr-1"></i>
                                                {{ product.days_since_last_sale !== null ? `${product.days_since_last_sale} d` : 'Nunca' }}
                                            </span>
                                        </div>
                                    </div>
                                </Link>
                            </li>
                        </ul>

                        <div v-else class="flex flex-col items-center justify-center flex-grow text-center py-6">
                            <div class="w-12 h-12 bg-green-50 dark:bg-green-900/20 rounded-full flex items-center justify-center mb-3">
                                <i class="pi pi-check text-green-500 !text-xl"></i>
                            </div>
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">¡Inventario Sano!</p>
                            <p class="text-[11px] text-gray-500 mt-1 max-w-[200px]">Todos tus productos en stock han tenido movimiento en los últimos 15 días.</p>
                        </div>
                    </div>
                </div>

                <!-- Fila 4: Clientes e Inventario -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
                    
                    <!-- Actividad Clientes -->
                    <div v-if="stats.recent_customers" class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                        <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase mb-6">Radar de Clientes</h2>
                        
                        <div class="mb-6">
                            <h3 class="text-[10px] uppercase tracking-wider text-gray-500 mb-3 border-b border-gray-100 dark:border-[#3a3a3a] pb-1">Nuevos Registros</h3>
                            <div class="flex flex-wrap gap-2">
                                <Link v-for="customer in stats.recent_customers" :key="customer.id"
                                    :href="route('customers.show', customer.id)"
                                    class="flex items-center gap-2 bg-gray-50 dark:bg-[#1a1a1a] pr-3 py-1 pl-1 rounded-full hover:bg-gray-100 dark:hover:bg-[#2a2a2a] transition-colors border border-transparent hover:border-gray-200 dark:hover:border-gray-700">
                                    <Avatar :label="getInitials(customer.name)" shape="circle" size="small" class="!bg-blue-100 !text-blue-600 dark:!bg-blue-900/30 dark:!text-blue-400 !text-xs" />
                                    <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ customer.name.split(' ')[0] }}</span>
                                </Link>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-[10px] uppercase tracking-wider text-gray-500 mb-3 border-b border-gray-100 dark:border-[#3a3a3a] pb-1">Frecuentes (Mes)</h3>
                            <div class="space-y-2">
                                <Link v-for="customer in stats.frequent_customers" :key="customer.id"
                                    :href="route('customers.show', customer.id)"
                                    class="flex items-center justify-between p-3 rounded-2xl bg-gray-50 dark:bg-[#1a1a1a] hover:bg-gray-100 dark:hover:bg-[#2a2a2a] transition-colors border border-transparent hover:border-gray-200 dark:hover:border-gray-700">
                                    <div class="flex items-center gap-3">
                                        <Avatar :label="getInitials(customer.name)" shape="circle" class="!bg-purple-100 !text-purple-600 dark:!bg-purple-900/30 dark:!text-purple-400" />
                                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ customer.name }}</span>
                                    </div>
                                    <div class="flex flex-col items-end">
                                        <span class="text-lg font-light text-gray-900 dark:text-white">{{ customer.transactions_count }}</span>
                                        <span class="text-[9px] text-gray-500 uppercase tracking-widest">compras</span>
                                    </div>
                                </Link>
                            </div>
                        </div>
                    </div>

                    <!-- Resumen Inventario (Estilo Batería/Almacenamiento) -->
                    <div v-if="stats.inventory_summary && hasPermission('dashboard.see_inventory_details')" 
                        class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col justify-between relative overflow-hidden">
                        <!-- Icono de fondo marca de agua -->
                        <i class="pi pi-box absolute -right-10 -bottom-10 !text-[150px] text-gray-50 dark:text-[#2a2a2a] z-0 pointer-events-none"></i>
                        
                        <div class="relative z-10">
                            <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase mb-6">Estado de Almacén</h2>
                            
                            <div class="grid grid-cols-2 gap-4 mb-8">
                                <div>
                                    <p class="text-[10px] text-gray-500 uppercase tracking-widest mb-1">Valor Venta</p>
                                    <p class="text-3xl font-light text-gray-900 dark:text-white">{{ formatCurrency(stats.inventory_summary.total_sale_value) }}</p>
                                </div>
                                <div>
                                    <p class="text-[10px] text-gray-500 uppercase tracking-widest mb-1">Costo Inversión</p>
                                    <p class="text-2xl font-light text-gray-500">{{ formatCurrency(stats.inventory_summary.total_cost) }}</p>
                                </div>
                            </div>

                            <div class="space-y-3">
                                <div class="flex justify-between items-end mb-1">
                                    <span class="text-xs font-medium text-gray-700 dark:text-gray-300">Niveles de Stock</span>
                                    <span class="text-[10px] text-gray-500">{{ stats.inventory_summary.total_products }} ítems totales</span>
                                </div>
                                <!-- Barra continua estilo Tesla UI -->
                                <div class="w-full bg-gray-100 dark:bg-[#1a1a1a] rounded-full h-3 flex overflow-hidden shadow-inner border border-gray-200 dark:border-[#3a3a3a]">
                                    <div class="bg-green-500 hover:brightness-110 transition-all" :style="{ width: `${inventoryPercentages.inStock}%` }" title="Con Stock"></div>
                                    <div class="bg-yellow-500 hover:brightness-110 transition-all" :style="{ width: `${inventoryPercentages.lowStock}%` }" title="Stock Bajo"></div>
                                    <div class="bg-red-500 hover:brightness-110 transition-all" :style="{ width: `${inventoryPercentages.outOfStock}%` }" title="Agotado"></div>
                                </div>
                                
                                <div class="flex justify-between pt-2">
                                    <div class="text-center">
                                        <div class="flex items-center justify-center gap-1.5 mb-1">
                                            <span class="w-2 h-2 rounded-full bg-green-500 shadow-[0_0_5px_rgba(34,197,94,0.5)]"></span>
                                            <span class="text-[10px] text-gray-500 uppercase">Óptimo</span>
                                        </div>
                                        <span class="text-lg font-light text-gray-900 dark:text-white">{{ stats.inventory_summary.in_stock_count }}</span>
                                    </div>
                                    <div class="text-center">
                                        <div class="flex items-center justify-center gap-1.5 mb-1">
                                            <span class="w-2 h-2 rounded-full bg-yellow-500 shadow-[0_0_5px_rgba(234,179,8,0.5)]"></span>
                                            <span class="text-[10px] text-gray-500 uppercase">Bajo</span>
                                        </div>
                                        <span class="text-lg font-light text-gray-900 dark:text-white">{{ stats.inventory_summary.low_stock_count }}</span>
                                    </div>
                                    <div class="text-center">
                                        <div class="flex items-center justify-center gap-1.5 mb-1">
                                            <span class="w-2 h-2 rounded-full bg-red-500 shadow-[0_0_5px_rgba(239,68,68,0.5)]"></span>
                                            <span class="text-[10px] text-gray-500 uppercase">Agotado</span>
                                        </div>
                                        <span class="text-lg font-light text-gray-900 dark:text-white">{{ stats.inventory_summary.out_of_stock_count }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty State minimalista -->
            <div v-else class="min-h-[60vh] flex flex-col items-center justify-center text-center px-4">
                <div class="w-24 h-24 bg-gray-50 dark:bg-[#1a1a1a] rounded-full flex items-center justify-center mb-6 border border-gray-100 dark:border-[#3a3a3a]">
                    <i class="pi pi-gauge !text-3xl text-primary-500"></i>
                </div>
                <h2 class="text-3xl font-light text-gray-900 dark:text-white tracking-tight">Bienvenido, {{ $page.props.auth.user.name }}</h2>
                <p class="text-gray-500 mt-3 text-sm max-w-md">Sistema inicializado. Aún no hay datos estadísticos para mostrar en este momento.</p>
            </div>
        </div>

        <!-- Modales (Estilos Limpios) -->
        <BankAccountHistoryModal v-if="selectedAccount" v-model:visible="isHistoryModalVisible"
            :account="selectedAccount" />
        <BankAccountTransferModal v-if="selectedAccount" v-model:visible="isTransferModalVisible"
            :account="selectedAccount" :all-accounts="allSubscriptionBankAccounts"
            @transfer-success="onTransferSuccess" />

        <!-- MODAL UNIFICADO: Apartados y Entregas (Estilo Pop-up Sistema) -->
        <Dialog v-model:visible="isInfoModalVisible" :header="modalTitle" 
            modal :style="{ width: '55rem' }" :breakpoints="{ '960px': '75vw', '640px': '95vw' }"
            :pt="{
                root: { class: 'dark:bg-[#232323] border-none shadow-2xl rounded-3xl overflow-hidden' },
                header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-8 py-6' },
                title: { class: 'text-xl font-light tracking-tight' },
                content: { class: 'dark:bg-[#232323] px-8 py-6' }
            }">
            
            <div v-if="isLoadingModal" class="flex flex-col items-center justify-center py-12">
                <i class="pi pi-spin pi-spinner-dotted !text-4xl text-primary-500 mb-4"></i>
                <span class="text-sm text-gray-500 uppercase tracking-widest animate-pulse">Sincronizando...</span>
            </div>

            <div v-else-if="modalItems.length > 0">
                <div class="bg-gray-50 dark:bg-[#1a1a1a] text-gray-700 dark:text-gray-300 p-4 rounded-2xl mb-6 text-sm flex gap-4 items-start border border-gray-100 dark:border-[#3a3a3a]">
                    <i class="pi pi-info-circle mt-0.5 !text-xl text-primary-500"></i>
                    <div>
                        <p class="font-medium mb-1 text-gray-900 dark:text-white">Guía de Acción</p>
                        <p class="text-xs text-gray-500" v-if="activeModalType === 'layaways'">
                            Selecciona el <strong>Folio</strong> para revisar detalles, aplicar extensiones o gestionar la cancelación.
                        </p>
                        <p class="text-xs text-gray-500" v-else>
                            Selecciona el <strong>Folio</strong> para verificar ruta, contactar al destinatario o registrar entrega exitosa.
                        </p>
                    </div>
                </div>

                <!-- Filtros (tipo y cliente) debajo de la guía -->
                <div v-if="activeModalType === 'layaways'" class="mb-6 flex flex-col sm:flex-row gap-3">
                    <SelectButton v-model="modalFilter" :options="modalFilterOptions" optionLabel="label" optionValue="value" :allowEmpty="false" class="w-full sm:w-auto"
                        :pt="{
                            root: { class: 'bg-gray-50 dark:bg-[#1a1a1a] rounded-full p-1 border border-gray-100 dark:border-[#3a3a3a] flex' },
                            button: ({ context }) => ({
                                class: [
                                    'rounded-full px-4 py-1.5 transition-colors focus:ring-0 !border-none text-[11px] font-bold uppercase tracking-widest flex-1 justify-center',
                                    context.active ? '!bg-primary-500 !text-white' : '!bg-transparent !text-gray-500 dark:!text-gray-400 hover:!text-gray-700 dark:hover:!text-gray-200'
                                ]
                            })
                        }" />

                    <Select v-model="customerFilter" :options="customerFilterOptions" optionLabel="label" optionValue="value" placeholder="Todos los clientes" showClear filter class="w-full sm:w-72"
                        :pt="{
                            root: { class: 'h-10 w-full min-w-0 !rounded-xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 !transition-colors flex items-center' },
                            label: { class: '!text-sm !text-gray-900 dark:!text-white' },
                            clearIcon: { class: '!text-gray-400' },
                            dropdown: { class: '!bg-transparent !text-gray-500 dark:!text-gray-400' },
                            overlay: { class: 'dark:!bg-[#232323] !rounded-2xl !border-gray-100 dark:!border-[#3a3a3a]' }
                        }" />
                </div>

                <div v-if="filteredModalItems.length > 0">
                <DataTable :value="filteredModalItems" responsiveLayout="scroll" paginator :rows="5" 
                    :pt="{
                        root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
                        headerRow: { class: 'bg-gray-50 dark:bg-[#1a1a1a]' },
                        headerCell: { class: 'bg-transparent text-xs uppercase tracking-widest text-gray-500 font-bold py-4 border-b border-gray-100 dark:border-[#3a3a3a]' },
                        row: { class: 'dark:bg-[#232323] hover:bg-gray-50 dark:hover:bg-[#2a2a2a] transition-colors' },
                        bodyCell: { class: 'py-4 border-b border-gray-50 dark:border-[#2a2a2a]' }
                    }">
                    <Column field="folio" header="Folio" sortable>
                        <template #body="{ data }">
                            <div class="flex flex-col items-start gap-1">
                                <Link :href="route('transactions.show', data.id)" class="text-primary-600 dark:text-primary-400 hover:text-primary-500 font-mono font-bold transition-colors">
                                    {{ data.folio }}
                                </Link>
                                <Tag :value="data.type === 'apartado' ? 'Apartado' : 'Crédito'" 
                                     :severity="data.type === 'apartado' ? 'info' : 'warn'" 
                                     class="!text-[9px] !px-2 !py-0.5 !rounded-full !uppercase !tracking-wider" />
                            </div>
                        </template>
                    </Column>
                    
                    <Column field="customer_name" header="Contacto" sortable>
                        <template #body="{ data }">
                            <Link v-if="data.customer_id" :href="route('customers.show', data.customer_id)" class="text-gray-900 dark:text-white hover:text-primary-500 font-medium transition-colors">
                                {{ data.customer_name }}
                            </Link>
                            <span v-else class="font-medium text-gray-900 dark:text-white">{{ data.customer_name }}</span>
                            <div v-if="data.customer_phone" class="text-[11px] text-gray-500 mt-1 flex items-center gap-1">
                                <i class="pi pi-phone !text-[9px]"></i> {{ data.customer_phone }}
                            </div>
                        </template>
                    </Column>

                    <Column v-if="activeModalType === 'layaways'" field="expiration_date" header="Estado" sortable>
                        <template #body="{ data }">
                            <Tag :value="data.expiration_date" :severity="getExpirationSeverity(data.days_remaining)" class="!rounded-md !font-mono !text-xs" />
                            <div class="text-[11px] font-medium mt-1" :class="data.days_remaining < 0 ? 'text-red-500' : 'text-gray-500'">
                                {{ data.days_remaining < 0 ? `Venció hace ${Math.abs(data.days_remaining)} d` : (data.days_remaining == 0 ? 'Vence hoy' : `En ${data.days_remaining} d`) }}
                            </div>
                        </template>
                    </Column>
                    
                    <Column v-else field="delivery_date" header="Logística" sortable>
                        <template #body="{ data }">
                            <span class="font-mono text-sm block text-gray-900 dark:text-white">{{ data.delivery_date }}</span>
                            <div class="text-[11px] mt-1 font-medium">
                                <span v-if="data.is_today" class="text-green-500 flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse"></span> Hoy</span>
                                <span v-else :class="data.days_remaining < 0 ? 'text-red-500' : 'text-gray-500'">
                                    {{ data.days_remaining < 0 ? `Retraso: ${Math.abs(data.days_remaining)} d` : `Programado: ${data.days_remaining} d` }}
                                </span>
                            </div>
                        </template>
                    </Column>

                    <Column v-if="activeModalType === 'layaways'" field="pending_amount" header="Balance" sortable>
                        <template #body="{ data }">
                            <span class="font-light text-lg text-red-500 tracking-tight">
                                {{ formatCurrency(data.pending_amount) }}
                            </span>
                        </template>
                    </Column>
                    
                    <Column v-else header="Destino">
                        <template #body="{ data }">
                            <div class="text-[11px] max-w-[180px] text-gray-600 dark:text-gray-400 leading-tight">
                                <span v-if="data.shipping_address" class="line-clamp-2" :title="data.shipping_address"><i class="pi pi-map-marker !text-[9px] mr-1"></i>{{ data.shipping_address }}</span>
                                <span v-else-if="data.notes" class="line-clamp-2" :title="data.notes"><i class="pi pi-comment !text-[9px] mr-1"></i>{{ data.notes }}</span>
                                <span v-else class="italic opacity-50">Sin ruta asignada</span>
                            </div>
                        </template>
                    </Column>
                </DataTable>
                </div>

                <div v-else class="flex flex-col items-center justify-center py-16 text-center">
                    <div class="w-16 h-16 rounded-full bg-gray-100 dark:bg-[#1a1a1a] flex items-center justify-center mb-4">
                        <i class="pi pi-filter !text-2xl text-gray-400"></i>
                    </div>
                    <h3 class="text-xl font-light text-gray-900 dark:text-white mb-2">Sin resultados</h3>
                    <p class="text-sm text-gray-500 max-w-xs">No hay vencimientos que coincidan con los filtros seleccionados.</p>
                </div>
            </div>

            <div v-else class="flex flex-col items-center justify-center py-16 text-center">
                <div class="w-16 h-16 rounded-full bg-green-500/10 flex items-center justify-center mb-4">
                    <i class="pi pi-check !text-2xl text-green-500"></i>
                </div>
                <h3 class="text-xl font-light text-gray-900 dark:text-white mb-2">Sistema en orden</h3>
                <p class="text-sm text-gray-500 max-w-xs" v-if="activeModalType === 'layaways'">No se detectaron vencimientos en el rango de alerta.</p>
                <p class="text-sm text-gray-500 max-w-xs" v-else>Cola de entregas despejada para los próximos días.</p>
            </div>
        </Dialog>

    </AppLayout>
</template>