<script setup>
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    session: {
        type: Object,
        required: true
    }
});

const activeTab = ref('cobros');

const paymentMethodDetails = {
    efectivo: { name: 'Efectivo', icon: 'pi pi-money-bill', color: 'text-green-600 dark:text-green-400' },
    tarjeta: { name: 'Tarjeta', icon: 'pi pi-credit-card', color: 'text-blue-600 dark:text-blue-400' },
    transferencia: { name: 'Transferencia', icon: 'pi pi-arrows-h', color: 'text-orange-500 dark:text-orange-400' },
    saldo: { name: 'Saldo de cliente', icon: 'pi pi-wallet', color: 'text-purple-500 dark:text-purple-400' },
};

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
const formatTime = (dateString) => new Date(dateString).toLocaleTimeString('es-MX', { hour: 'numeric', minute: '2-digit' });

// --- ORDENAMIENTO DE VENTAS ---
const sort = ref({ field: 'created_at', order: -1 });
const onSort = (event) => {
    sort.value.field = event.sortField;
    sort.value.order = event.sortOrder;
};

const sortedTransactions = computed(() => {
    if (!props.session?.transactions) return [];
    return [...props.session.transactions].sort((a, b) => {
        let valA, valB;
        const field = sort.value.field;
        if (field === 'created_at') { valA = new Date(a.created_at); valB = new Date(b.created_at); }
        else if (field === 'total') { valA = parseFloat(a.total); valB = parseFloat(b.total); }
        else {
            valA = field.split('.').reduce((o, i) => o?.[i], a);
            valB = field.split('.').reduce((o, i) => o?.[i], b);
        }
        if (valA < valB) return -1 * sort.value.order;
        if (valA > valB) return 1 * sort.value.order;
        return 0;
    });
});

// --- ORDENAMIENTO DE COBROS (PAGOS) ---
const sortPayments = ref({ field: 'created_at', order: -1 });
const onSortPayments = (event) => {
    sortPayments.value.field = event.sortField;
    sortPayments.value.order = event.sortOrder;
};

const sortedPaymentsArray = computed(() => {
    if (!props.session?.payments) return [];
    return [...props.session.payments].sort((a, b) => {
        let valA, valB;
        const field = sortPayments.value.field;
        
        if (field === 'created_at') { valA = new Date(a.created_at); valB = new Date(b.created_at); }
        else if (field === 'amount') { valA = parseFloat(a.amount); valB = parseFloat(b.amount); }
        else if (field === 'transaction.folio') { valA = a.transaction?.folio || ''; valB = b.transaction?.folio || ''; }
        else {
            valA = field.split('.').reduce((o, i) => o?.[i], a) || '';
            valB = field.split('.').reduce((o, i) => o?.[i], b) || '';
        }
        
        if (valA < valB) return -1 * sortPayments.value.order;
        if (valA > valB) return 1 * sortPayments.value.order;
        return 0;
    });
});

// --- TESLA UI PASS-THROUGH (PT) ---
const dataTablePt = {
    root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
    headerRow: { class: 'bg-gray-50 dark:bg-[#1a1a1a]' },
    headerCell: { class: 'bg-transparent text-[10px] uppercase tracking-widest text-gray-500 font-bold py-4 px-4 border-b border-gray-100 dark:border-[#3a3a3a]' },
    bodyRow: { class: 'dark:bg-[#232323] hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors text-sm text-gray-700 dark:text-gray-300 group' },
    bodyCell: { class: 'py-4 px-4 border-b border-gray-50 dark:border-[#2a2a2a]' },
};

const tagPt = {
    root: { class: '!rounded-full !px-2 !py-0.5 !text-[9px] !uppercase !tracking-widest !font-bold' }
};
</script>

<template>
    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
        
        <!-- Header -->
        <div class="mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div>
                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Detalle operativo</h2>
                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Registro de transacciones de la sesión</p>
            </div>

            <!-- PESTAÑAS TIPO SEGMENT CONTROL -->
            <div class="flex items-center gap-1 bg-gray-50 dark:bg-[#1a1a1a] p-1.5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <button @click="activeTab = 'cobros'" 
                    class="px-4 py-2 rounded-xl text-[10px] font-bold uppercase tracking-widest transition-all outline-none"
                    :class="activeTab === 'cobros' ? 'bg-white dark:bg-[#232323] text-gray-900 dark:text-white shadow-sm border border-gray-200 dark:border-[#3a3a3a]' : 'text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 border border-transparent'">
                    <i class="pi pi-money-bill mr-1.5 !text-[10px]"></i> Desglose de cobros
                </button>
                <button @click="activeTab = 'ventas'" 
                    class="px-4 py-2 rounded-xl text-[10px] font-bold uppercase tracking-widest transition-all outline-none"
                    :class="activeTab === 'ventas' ? 'bg-white dark:bg-[#232323] text-gray-900 dark:text-white shadow-sm border border-gray-200 dark:border-[#3a3a3a]' : 'text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 border border-transparent'">
                    <i class="pi pi-shopping-cart mr-1.5 !text-[10px]"></i> Ventas generadas
                </button>
            </div>
        </div>

        <!-- TAB: COBROS (PAGOS RECIBIDOS) -->
        <div v-show="activeTab === 'cobros'" class="max-h-[400px] overflow-y-auto custom-scrollbar">
            <DataTable :value="sortedPaymentsArray" :pt="dataTablePt" responsiveLayout="scroll" @sort="onSortPayments" :sortField="sortPayments.field" :sortOrder="sortPayments.order">
                
                <Column field="created_at" header="Hora" sortable>
                    <template #body="{ data }">
                        <span class="text-xs text-gray-600 dark:text-gray-400">{{ formatTime(data.created_at) }}</span>
                    </template>
                </Column>
                
                <Column field="transaction.folio" header="Venta / Folio" sortable>
                    <template #body="{ data }">
                        <Link v-if="data.transaction" :href="route('transactions.show', data.transaction.id)" class="font-mono text-primary-600 dark:text-primary-400 font-medium hover:underline m-0 transition-colors">
                            {{ data.transaction.folio }}
                        </Link>
                        <span v-else class="text-gray-400 text-xs italic m-0">Sin folio</span>
                    </template>
                </Column>
                
                <Column header="Concepto">
                    <template #body="{ data }">
                        <Tag v-if="data.transaction?.cash_register_session_id === session.id" severity="success" value="Venta nueva" :pt="tagPt" />
                        <Tag v-else severity="warn" value="Abono (Venta pasada)" :pt="tagPt" />
                    </template>
                </Column>

                <Column field="transaction.customer.name" header="Cliente">
                    <template #body="{ data }">
                        <span class="font-medium m-0">{{ data.transaction?.customer?.name || 'Público general' }}</span>
                    </template>
                </Column>

                <Column field="payment_method" header="Método" sortable>
                    <template #body="{ data }">
                        <div class="flex items-center gap-2">
                            <i class="pi !text-xs" :class="paymentMethodDetails[data.payment_method]?.icon + ' ' + paymentMethodDetails[data.payment_method]?.color"></i>
                            <span class="text-xs capitalize text-gray-700 dark:text-gray-300">{{ paymentMethodDetails[data.payment_method]?.name || data.payment_method }}</span>
                        </div>
                    </template>
                </Column>

                <Column field="amount" header="Monto Cobrado" sortable>
                    <template #body="{ data }">
                        <span class="font-mono font-bold text-gray-900 dark:text-white">{{ formatCurrency(data.amount) }}</span>
                    </template>
                </Column>

                <template #empty>
                    <div class="flex flex-col items-center justify-center py-10 opacity-60">
                        <i class="pi pi-money-bill !text-3xl text-gray-400 mb-3"></i>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin registros</p>
                        <p class="text-xs text-gray-400 mt-1">No se registraron cobros en esta sesión.</p>
                    </div>
                </template>
            </DataTable>
        </div>

        <!-- TAB: VENTAS CREADAS -->
        <div v-show="activeTab === 'ventas'" class="max-h-[400px] overflow-y-auto custom-scrollbar">
            <!-- Aviso estético tipo Alerta Info -->
            <div class="mb-4 bg-blue-50 dark:bg-blue-900/10 p-4 rounded-2xl flex items-start gap-3 border border-blue-100 dark:border-blue-900/30">
                <i class="pi pi-info-circle mt-0.5 !text-lg text-blue-500"></i>
                <div>
                    <p class="text-[10px] font-bold text-blue-500 dark:text-blue-400 uppercase tracking-widest m-0 mb-1">Nota informativa</p>
                    <p class="text-xs text-blue-800 dark:text-blue-300 m-0 leading-relaxed">
                        Esta tabla muestra el valor total de la <strong>mercancía vendida o apartada</strong> durante esta sesión. No representa necesariamente el dinero que ingresó a la caja (ej. si se dejó un artículo a crédito).
                    </p>
                </div>
            </div>

            <DataTable :value="sortedTransactions" :pt="dataTablePt" responsiveLayout="scroll" @sort="onSort" :sortField="sort.field" :sortOrder="sort.order">
                
                <Column field="folio" header="Folio" sortable>
                    <template #body="{ data }">
                        <Link :href="route('transactions.show', data.id)" class="font-mono text-primary-600 dark:text-primary-400 font-medium hover:underline m-0 transition-colors">
                            {{ data.folio }}
                        </Link>
                    </template>
                </Column>
                
                <Column field="created_at" header="Hora" sortable>
                    <template #body="{ data }">
                        <span class="text-xs text-gray-600 dark:text-gray-400">{{ formatTime(data.created_at) }}</span>
                    </template>
                </Column>
                
                <Column field="user.name" header="Cajero" sortable>
                    <template #body="{ data }">
                        <span class="font-medium text-gray-900 dark:text-gray-100 m-0">{{ data.user?.name || 'Sistema' }}</span>
                    </template>
                </Column>
                
                <Column field="total" header="Valor de Mercancía" sortable>
                    <template #body="{ data }">
                        <span class="font-mono font-bold text-gray-900 dark:text-white">{{ formatCurrency(data.total) }}</span>
                    </template>
                </Column>

                <template #empty>
                    <div class="flex flex-col items-center justify-center py-10 opacity-60">
                        <i class="pi pi-shopping-cart !text-3xl text-gray-400 mb-3"></i>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin operaciones</p>
                        <p class="text-xs text-gray-400 mt-1">No hay ventas generadas en esta sesión.</p>
                    </div>
                </template>
            </DataTable>
        </div>
    </div>
</template>