<script setup>
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import axios from 'axios'; 
import AppLayout from '@/Layouts/AppLayout.vue';
import BankAccountHistoryModal from '@/Components/BankAccountHistoryModal.vue';
import BankAccountTransferModal from '@/Components/BankAccountTransferModal.vue';
import { usePermissions } from '@/Composables'; 

const props = defineProps({
    stats: Object,
    userBankAccounts: Array,
    allSubscriptionBankAccounts: Array,
});

const { hasPermission } = usePermissions();

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

const modalTitle = computed(() => {
    return activeModalType.value === 'layaways' 
        ? 'Apartados por vencer (Próximos 3 días)' 
        : 'Próximas entregas de pedidos';
});

const fetchExpiringLayaways = async () => {
    activeModalType.value = 'layaways';
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
    isInfoModalVisible.value = true;
    isLoadingModal.value = true;
    modalItems.value = [];
    
    try {
        // Asegúrate de agregar esta ruta en tu archivo de rutas
        const response = await axios.get(route('dashboard.upcoming-deliveries'));
        modalItems.value = response.data;
    } catch (error) {
        console.error("Error cargando pedidos:", error);
    } finally {
        isLoadingModal.value = false;
    }
};

const getExpirationSeverity = (days) => {
    if (days < 0) return 'danger'; // Vencido
    if (days <= 2) return 'danger'; // Muy urgente
    return 'warning'; // Por vencer
};

</script>

<template>
    <AppLayout title="Dashboard">
        <div class="p-4 md:p-6 lg:p-8 space-y-6">
            <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">Inicio</h1>

            <div v-if="hasStatsToShow">
                <!-- Fila 1: KPIs Principales -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <Link v-if="stats.today_sales !== undefined && hasPermission('dashboard.see_sales')" :href="route('transactions.index')"
                        class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md hover:shadow-xl transition-shadow border-l-4 border-green-500">
                        <div class="flex justify-between items-start">
                            <div>
                                <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 m-0">Ventas de hoy</h2>
                                <small class="text-gray-500">Click para ir a módulo de ventas</small>
                                <p class="text-3xl font-bold mt-2 text-green-500">{{ formatCurrency(stats.today_sales)
                                    }}
                                </p>
                            </div>
                            <i
                                class="pi pi-dollar text-green-500 p-3 bg-green-100 dark:bg-green-900/50 rounded-full"></i>
                        </div>
                        <div v-if="salesChange" class="text-xs mt-2"
                            :class="salesChange.sign === '+' ? 'text-green-500' : 'text-red-500'">
                            <span v-if="salesChange.value > 0">{{ salesChange.sign }}{{ salesChange.value }}% vs
                                ayer</span>
                            <span v-else class="text-gray-500">Sin cambios vs ayer</span>
                        </div>
                    </Link>
                    
                    <!-- Apartados por Vencer -->
                    <div v-if="stats.expiring_layaways_count !== undefined && hasPermission('dashboard.see_layaways')" 
                        @click="fetchExpiringLayaways"
                        class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md hover:shadow-xl transition-shadow cursor-pointer border-l-4 border-purple-500 group">
                        <div class="flex justify-between items-start">
                            <div>
                                <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 m-0">Apartados por vencer</h2>
                                <small class="text-gray-500">Click para ver detalles</small>
                                <p class="text-3xl font-bold mt-2 text-purple-600 dark:text-purple-400">
                                    {{ stats.expiring_layaways_count }}
                                </p>
                                <p class="text-xs text-gray-400 mt-1">Próximos 3 días</p>
                            </div>
                                <i class="pi pi-clock p-3 bg-purple-100 dark:bg-purple-900/50 rounded-full text-purple-600" :class="{ 'animate-bounce': stats.expiring_layaways_count > 0 }"></i>
                        </div>
                    </div>

                    <!-- NUEVO PANEL: Próximas Entregas -->
                    <div v-if="stats.upcoming_deliveries_count !== undefined && hasPermission('dashboard.see_orders')" 
                        @click="fetchUpcomingDeliveries"
                        class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md hover:shadow-xl transition-shadow cursor-pointer border-l-4 border-blue-500 group">
                        <div class="flex justify-between items-start">
                            <div>
                                <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 m-0">Próximas entregas</h2>
                                <small class="text-gray-500">Click para gestionar envíos</small>
                                <p class="text-3xl font-bold mt-2 text-blue-600 dark:text-blue-400">
                                    {{ stats.upcoming_deliveries_count }}
                                </p>
                                <p class="text-xs text-gray-400 mt-1">Pendientes de entrega</p>
                            </div>
                            <i class="pi pi-truck p-3 bg-blue-100 dark:bg-blue-900/50 rounded-full text-blue-600" :class="{ 'animate-bounce': stats.upcoming_deliveries_count > 0 }"></i>
                        </div>
                    </div>

                    <Link v-if="stats.total_customer_debt !== undefined && hasPermission('dashboard.see_outstanding_balances')" :href="route('customers.index')"
                        class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md hover:shadow-xl transition-shadow border-l-4 border-cyan-500">
                        <div class="flex justify-between items-start">
                            <div>
                                <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 m-0">Saldo por cobrar</h2>
                                <small class="text-gray-500">Click para ir a clientes</small>
                                <p class="text-3xl font-bold mt-2 text-cyan-500">{{
                                    formatCurrency(stats.total_customer_debt) }}</p>
                                <p class="text-xs text-gray-400">Total de clientes</p>
                            </div>
                            <i
                                class="pi pi-credit-card text-cyan-500 p-3 bg-cyan-100 dark:bg-cyan-900/50 rounded-full"></i>
                        </div>
                    </Link>
                </div>

                <!-- Fila 2: Gráfico de Ventas y Resumen de Módulos -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-6">
                    <div v-if="stats.weekly_sales_trend && hasPermission('dashboard.see_sales')"
                        class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md col-span-1 lg:col-span-2 min-h-[200px]">
                        <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 mb-4">
                            Tendencia de ventas semanal</h2>
                        <div class="flex justify-around items-end h-[80%]">
                            <div v-for="day in stats.weekly_sales_trend" :key="day.day"
                                class="text-center w-full group flex flex-col items-center justify-end h-full">
                                <div v-tooltip.top="formatCurrency(day.total)"
                                    class="bg-orange-400 w-3/4 rounded-t-md mx-auto transition-all hover:bg-orange-500"
                                    :style="{ height: `${(day.total / maxWeeklySale) * 100}%`, minHeight: '2px' }">
                                </div>
                                <p class="text-xs mt-2 font-semibold">{{ day.day }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="space-y-6">
                        <Link v-if="stats.cash_registers_status" :href="route('cash-registers.index')"
                            class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md hover:shadow-xl transition-shadow block">
                            <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400">Estado de cajas</h2>
                            <div class="mt-3 space-y-2">
                                <div class="flex justify-between items-center"><span
                                        class="text-green-500 flex items-center gap-2"><i
                                            class="pi pi-circle-fill text-xs"></i> En uso</span>
                                            <span class="font-bold text-2xl">{{ stats.cash_registers_status.in_use_count || 0 }}</span>
                                </div>
                                <div class="flex justify-between items-center"><span
                                        class="text-gray-400 flex items-center gap-2"><i
                                            class="pi pi-circle-fill text-xs"></i> Sin usar</span>
                                            <span class="font-bold text-2xl">{{ stats.cash_registers_status.available_count || 0 }}</span>
                                </div>
                            </div>
                        </Link>
                        <Link v-if="stats.service_orders_status" :href="route('service-orders.index')"
                            class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md hover:shadow-xl transition-shadow block">
                            <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400">Órdenes de servicio</h2>
                            <div class="grid grid-cols-2 gap-4 mt-3 text-center">
                                <div>
                                    <p class="font-bold text-2xl">{{ getServiceOrderStatus('pendiente') }}</p>
                                    <p class="text-xs">Pendientes</p>
                                </div>
                                <div>
                                    <p class="font-bold text-2xl">{{ getServiceOrderStatus('en_proceso') }}</p>
                                    <p class="text-xs">En proceso</p>
                                </div>
                                <div>
                                    <p class="font-bold text-2xl">{{ getServiceOrderStatus('completado') }}</p>
                                    <p class="text-xs">Completadas</p>
                                </div>
                                <div>
                                    <p class="font-bold text-2xl text-green-500">{{ getServiceOrderStatus('entregado')
                                        }}
                                    </p>
                                    <p class="text-xs">Entregadas</p>
                                </div>
                            </div>
                        </Link>
                    </div>
                </div>

                <!-- NUEVO: Fila de Cuentas Bancarias -->
                <div v-if="userBankAccounts" class="mt-6">
                    <Card>
                        <template #title>Cuentas bancarias</template>
                        <template #subtitle>Balance actual y acciones rápidas.</template>
                        <template #content>
                            <div v-if="userBankAccounts.length > 0">
                                <div
                                    class="flex justify-between items-center mb-4 pb-2 border-b border-dashed dark:border-gray-700">
                                    <span class="font-bold">Balance Total</span>
                                    <span class="font-bold text-lg">{{ formatCurrency(totalBalance) }}</span>
                                </div>
                                <ul class="space-y-3 max-h-60 overflow-y-auto pr-2">
                                    <li v-for="account in userBankAccounts" :key="account.id"
                                        class="flex justify-between items-center">
                                        <div>
                                            <p class="font-semibold">{{ account.account_name }}</p>
                                            <p class="text-sm text-gray-500">{{ account.bank_name }}</p>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span class="font-mono font-bold">{{ formatCurrency(account.balance)
                                                }}</span>
                                            <Button icon="pi pi-ellipsis-v" text rounded
                                                @click="toggleMenu($event, account)" />
                                        </div>
                                    </li>
                                </ul>
                                <Menu ref="menu" :model="menuItems" :popup="true" />
                            </div>
                            <p v-else class="text-center text-gray-500 py-4">No tienes cuentas bancarias asignadas para
                                administrar.</p>
                        </template>
                    </Card>
                </div>

                <!-- Fila 3: Productos y Clientes -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
                    <div v-if="stats.top_selling_products && stats.top_selling_products.length > 0"
                        class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                        <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 mb-4">Top 5 productos más
                            vendidos (mes)</h2>
                        <ul class="space-y-3">
                            <li v-for="(product, index) in stats.top_selling_products" :key="`${product.id}-${index}`"
                                :class="{ 'border-b dark:border-gray-700 pb-3': index < stats.top_selling_products.length - 1 }">
                                <Link :href="route('products.show', product.id)"
                                    class="flex items-center gap-4 hover:bg-gray-50 dark:hover:bg-gray-700 p-2 -m-2 rounded-md">
                                    <img v-if="product.image" :src="product.image" :alt="product.name"
                                        class="w-12 h-12 rounded-md object-contain">
                                    <Avatar v-else :label="getInitials(product.name)" shape="circle" />
                                    <div class="flex-grow">
                                        <p class="font-semibold text-sm m-0">{{ product.name }}</p>
                                        <p v-if="product.variant_description"
                                            class="text-xs text-orange-500 m-0 font-medium">
                                            {{ product.variant_description }}
                                        </p>
                                        <p class="text-xs text-gray-500 m-0">{{ product.total_sold }} unidades</p>
                                    </div>
                                    <p class="font-semibold text-sm">{{ formatCurrency(product.selling_price) }}</p>
                                </Link>
                            </li>
                        </ul>
                        <Link :href="route('products.index')" class="w-full mt-4">
                            <Button label="Ver todos los productos" severity="secondary" text class="w-full" />
                        </Link>
                    </div>

                    <div v-if="stats.low_turnover_products && stats.low_turnover_products.length > 0"
                        class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                        <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400">Productos con baja rotación
                        </h2>
                        <p class="text-xs text-gray-400 mt-1">Estos productos tienen 15 días o más sin ventas.</p>
                        <ul class="space-y-3 mt-4">
                            <li v-for="(product, index) in stats.low_turnover_products" :key="product.id"
                                :class="{ 'border-b dark:border-gray-700 pb-3': index < stats.low_turnover_products.length - 1 }">
                                <Link :href="route('products.show', product.id)"
                                    class="flex items-center gap-4 hover:bg-gray-50 dark:hover:bg-gray-700 p-2 -m-2 rounded-md">
                                    <img v-if="product.image" :src="product.image" :alt="product.name"
                                        class="w-12 h-12 rounded-md object-contain flex-shrink-0">
                                    <Avatar v-else :label="getInitials(product.name)" shape="circle" />
                                    <div class="flex-grow overflow-hidden">
                                        <p class="font-semibold text-sm truncate m-0">{{ product.name }}</p>
                                        <p class="text-xs text-gray-500 flex flex-wrap items-center gap-x-2 m-0">
                                            <span>{{ formatCurrency(product.selling_price) }}</span>
                                            <span class="text-gray-300 dark:text-gray-600">•</span>
                                            <span v-if="product.days_since_last_sale !== null">{{
                                                product.days_since_last_sale }} días sin ventas</span>
                                            <span v-else>Nunca vendido</span>
                                            <span class="text-gray-300 dark:text-gray-600">•</span>
                                            <span>{{ product.current_stock }} existencias</span>
                                        </p>
                                    </div>
                                </Link>
                            </li>
                        </ul>
                        <Link :href="route('products.index')" class="w-full mt-4">
                            <Button label="Ver todos los productos" severity="secondary" text class="w-full" />
                        </Link>
                    </div>
                </div>

                <!-- Fila 4: Clientes e Inventario -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
                    <div v-if="stats.recent_customers" class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                        <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 mb-4">Actividad de clientes
                        </h2>
                        <div>
                            <h3 class="font-semibold text-xs text-gray-600 dark:text-gray-300">Nuevos clientes
                                (recientes)</h3>
                            <div class="space-y-1 mt-2">
                                <Link v-for="customer in stats.recent_customers" :key="customer.id"
                                    :href="route('customers.show', customer.id)"
                                    class="flex items-center justify-between p-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700">
                                    <div class="flex items-center gap-3">
                                        <Avatar :label="getInitials(customer.name)" shape="circle" />
                                        <span class="text-sm font-medium">{{ customer.name }}</span>
                                    </div>
                                    <i class="pi pi-arrow-right text-xs text-gray-400"></i>
                                </Link>
                            </div>
                        </div>
                        <div class="mt-4">
                            <h3 class="font-semibold text-xs text-gray-600 dark:text-gray-300">Clientes frecuentes (Mes)
                            </h3>
                            <div class="space-y-1 mt-2">
                                <Link v-for="customer in stats.frequent_customers" :key="customer.id"
                                    :href="route('customers.show', customer.id)"
                                    class="flex items-center justify-between p-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700">
                                    <div class="flex items-center gap-3">
                                        <Avatar :label="getInitials(customer.name)" shape="circle"
                                            class="!bg-purple-100 !text-purple-600" />
                                        <span class="text-sm font-medium">{{ customer.name }}</span>
                                    </div>
                                    <span class="text-xs text-gray-500">{{ customer.transactions_count }} compras</span>
                                </Link>
                            </div>
                        </div>
                    </div>

                    <div v-if="stats.inventory_summary && hasPermission('dashboard.see_inventory_details')" class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                        <div class="flex justify-between items-center mb-1">
                            <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400">Total en inventario</h2>
                            <i class="pi pi-box text-gray-400"></i>
                        </div>
                        <p class="text-2xl font-bold">{{ formatCurrency(stats.inventory_summary.total_sale_value) }}
                            <span class="text-xs text-gray-500">Para venta</span>
                        </p>
                        <p class="text-2xl font-bold">{{ formatCurrency(stats.inventory_summary.total_cost) }} <span
                                class="text-xs text-gray-500">Invertido</span></p>
                        <p class="text-sm text-gray-500">{{ stats.inventory_summary.total_products }} productos</p>
                        <div class="mt-4">
                            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2 flex overflow-hidden">
                                <div class="bg-green-500 h-2" :style="{ width: `${inventoryPercentages.inStock}%` }">
                                </div>
                                <div class="bg-yellow-500 h-2" :style="{ width: `${inventoryPercentages.lowStock}%` }">
                                </div>
                                <div class="bg-red-500 h-2" :style="{ width: `${inventoryPercentages.outOfStock}%` }">
                                </div>
                            </div>
                            <div class="flex justify-between text-xs mt-2">
                                <span class="flex items-center gap-1.5"><i
                                        class="pi pi-circle-fill text-green-500 text-[8px]"></i>Con
                                    stock: {{
                                        stats.inventory_summary.in_stock_count }}</span>
                                <span class="flex items-center gap-1.5"><i
                                        class="pi pi-circle-fill text-yellow-500 text-[8px]"></i>Bajo
                                    stock: {{
                                        stats.inventory_summary.low_stock_count }}</span>
                                <span class="flex items-center gap-1.5"><i
                                        class="pi pi-circle-fill text-red-500 text-[8px]"></i>Agotado: {{
                                            stats.inventory_summary.out_of_stock_count }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else class="bg-white dark:bg-gray-800 p-8 rounded-lg shadow-md text-center">
                <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200">Bienvenido(a), {{
                    $page.props.auth.user.name }}</h2>
                <p class="text-gray-500 mt-2">¡Que tengas un excelente día de trabajo!</p>
            </div>
        </div>

        <!-- Modales -->
        <BankAccountHistoryModal v-if="selectedAccount" v-model:visible="isHistoryModalVisible"
            :account="selectedAccount" />
        <BankAccountTransferModal v-if="selectedAccount" v-model:visible="isTransferModalVisible"
            :account="selectedAccount" :all-accounts="allSubscriptionBankAccounts"
            @transfer-success="onTransferSuccess" />

        <!-- MODAL UNIFICADO: Apartados y Entregas -->
        <Dialog v-model:visible="isInfoModalVisible" :header="modalTitle" 
            modal :style="{ width: '50rem' }" :breakpoints="{ '960px': '75vw', '640px': '95vw' }">
            
            <div v-if="isLoadingModal" class="flex justify-center p-8">
                <i class="pi pi-spin pi-spinner !text-4xl text-purple-500"></i>
            </div>

            <div v-else-if="modalItems.length > 0">
                <!-- MENSAJE INFORMATIVO DINÁMICO -->
                <div class="bg-blue-50 dark:bg-blue-900/20 text-blue-800 dark:text-blue-200 p-3 rounded-lg mb-4 text-sm flex gap-3 items-start border border-blue-200 dark:border-blue-800">
                    <i class="pi pi-info-circle mt-0.5 text-lg"></i>
                    <div>
                        <p class="font-bold mb-0">¿Qué deseas hacer?</p>
                        <p class="mt-0" v-if="activeModalType === 'layaways'">
                            Haz clic en el <strong>Folio</strong> para ver detalles, extender la fecha o cancelar el apartado.
                        </p>
                        <p class="mt-0" v-else>
                            Haz clic en el <strong>Folio</strong> para ver detalles, contactar al cliente o marcar el pedido como entregado.
                        </p>
                    </div>
                </div>

                <DataTable :value="modalItems" class="p-datatable-sm" responsiveLayout="scroll" paginator :rows="5">
                    <Column field="folio" header="Folio" sortable>
                        <template #body="{ data }">
                            <Link :href="route('transactions.show', data.id)" class="text-blue-600 hover:underline font-bold">
                                {{ data.folio }}
                            </Link>
                        </template>
                    </Column>
                    
                    <Column field="customer_name" header="Cliente/Contacto" sortable>
                        <template #body="{ data }">
                            <Link v-if="data.customer_id" :href="route('customers.show', data.customer_id)" class="text-blue-600 hover:underline font-medium">
                                {{ data.customer_name }}
                            </Link>
                            <span v-else class="font-medium">{{ data.customer_name }}</span>
                            <div v-if="data.customer_phone" class="text-xs text-gray-500">
                                {{ data.customer_phone }}
                            </div>
                        </template>
                    </Column>

                    <!-- COLUMNA DINÁMICA: Fecha (Vencimiento o Entrega) -->
                    <Column v-if="activeModalType === 'layaways'" field="expiration_date" header="Vence" sortable>
                        <template #body="{ data }">
                            <Tag :value="data.expiration_date" :severity="getExpirationSeverity(data.days_remaining)" />
                            <div class="text-xs text-gray-500 mt-1">
                                {{ data.days_remaining < 0 ? `Venció hace ${Math.abs(data.days_remaining)} día(s)` : (data.days_remaining == 0 ? 'Vence hoy' : `En ${data.days_remaining} día(s)`) }}
                            </div>
                        </template>
                    </Column>
                    <Column v-else field="delivery_date" header="Entrega" sortable>
                        <template #body="{ data }">
                            <span class="font-bold text-sm block">{{ data.delivery_date }}</span>
                            <div class="text-xs text-gray-500 mt-1">
                                <span v-if="data.is_today" class="text-green-600 font-bold">¡Es hoy!</span>
                                <span v-else>{{ data.days_remaining < 0 ? `Atrasado ${Math.abs(data.days_remaining)} día(s)` : `En ${data.days_remaining} día(s)` }}</span>
                            </div>
                        </template>
                    </Column>

                    <!-- COLUMNA DINÁMICA: Monto o Dirección -->
                    <Column v-if="activeModalType === 'layaways'" field="pending_amount" header="Pendiente" sortable>
                        <template #body="{ data }">
                            <span class="font-mono font-bold text-red-500">
                                {{ formatCurrency(data.pending_amount) }}
                            </span>
                        </template>
                    </Column>
                    <Column v-else header="Dirección/Notas">
                        <template #body="{ data }">
                            <div class="text-xs max-w-[200px] truncate" :title="data.shipping_address || data.notes">
                                <span v-if="data.shipping_address"><i class="pi pi-map-marker text-gray-400 mr-1"></i>{{ data.shipping_address }}</span>
                                <span v-else-if="data.notes"><i class="pi pi-comment text-gray-400 mr-1"></i>{{ data.notes }}</span>
                                <span v-else class="text-gray-400 italic">Sin detalles</span>
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </div>

            <div v-else class="text-center py-8 text-gray-500">
                <i class="pi pi-check-circle !text-4xl text-green-500 mb-2"></i>
                <p v-if="activeModalType === 'layaways'">¡Todo en orden! No hay apartados próximos a vencer.</p>
                <p v-else>¡Excelente! No hay entregas pendientes para los próximos días.</p>
            </div>
        </Dialog>

    </AppLayout>
</template>