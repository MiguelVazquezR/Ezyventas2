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
    efectivo: { name: 'Efectivo', icon: 'pi pi-money-bill', color: 'text-green-600' },
    tarjeta: { name: 'Tarjeta', icon: 'pi pi-credit-card', color: 'text-blue-600' },
    transferencia: { name: 'Transferencia', icon: 'pi pi-arrows-h', color: 'text-orange-500' },
    saldo: { name: 'Saldo de cliente', icon: 'pi pi-wallet', color: 'text-purple-500' },
};

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
const formatTime = (dateString) => new Date(dateString).toLocaleTimeString('es-MX', { hour: 'numeric', minute: '2-digit' });

const getPaymentsForTransaction = (transactionId) => {
    return (props.session.payments || []).filter(p => p.transaction_id === transactionId);
};

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
</script>

<template>
    <Card>
        <template #title>Detalle Operativo</template>
        <template #content>
            
            <!-- PESTAÑAS (TABS) PARA SEPARAR COBROS DE VENTAS -->
            <div class="flex gap-4 border-b border-gray-200 dark:border-gray-700 mb-4">
                <button @click="activeTab = 'cobros'" :class="['pb-2 font-semibold text-sm transition-colors border-b-2 outline-none', activeTab === 'cobros' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300']">
                    <i class="pi pi-money-bill mr-1"></i> Desglose de Cobros (Ingresos Reales)
                </button>
                <button @click="activeTab = 'ventas'" :class="['pb-2 font-semibold text-sm transition-colors border-b-2 outline-none', activeTab === 'ventas' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300']">
                    <i class="pi pi-shopping-cart mr-1"></i> Ventas Generadas (Mercancía)
                </button>
            </div>

            <!-- TAB: COBROS (PAGOS RECIBIDOS) -->
            <div v-show="activeTab === 'cobros'" class="max-h-[400px] overflow-y-auto">
                <DataTable :value="sortedPaymentsArray" class="p-datatable-sm" responsiveLayout="scroll" @sort="onSortPayments" :sortField="sortPayments.field" :sortOrder="sortPayments.order">
                    <template #empty><div class="text-center py-4 text-gray-500">No se registraron cobros en esta sesión.</div></template>
                    
                    <Column field="created_at" header="Hora" sortable>
                        <template #body="{ data }">{{ formatTime(data.created_at) }}</template>
                    </Column>
                    
                    <Column field="transaction.folio" header="Venta / Folio" sortable>
                        <template #body="{ data }">
                            <Link v-if="data.transaction" :href="route('transactions.show', data.transaction.id)" class="text-primary-600 dark:text-primary-400 font-medium hover:underline">
                                {{ data.transaction.folio }}
                            </Link>
                            <span v-else class="text-gray-400">Sin folio</span>
                        </template>
                    </Column>
                    
                    <Column header="Concepto">
                        <template #body="{ data }">
                            <Tag v-if="data.transaction?.cash_register_session_id === session.id" severity="success" value="Venta nueva" class="!text-[10px]" />
                            <Tag v-else severity="warning" value="Abono a venta pasada" class="!text-[10px]" />
                        </template>
                    </Column>

                    <Column field="transaction.customer.name" header="Cliente">
                        <template #body="{ data }">{{ data.transaction?.customer?.name || 'Público en general' }}</template>
                    </Column>

                    <Column field="payment_method" header="Método" sortable>
                        <template #body="{ data }">
                            <div class="flex items-center gap-2">
                                <i class="pi" :class="paymentMethodDetails[data.payment_method]?.icon + ' ' + paymentMethodDetails[data.payment_method]?.color"></i>
                                <span class="text-xs capitalize">{{ paymentMethodDetails[data.payment_method]?.name || data.payment_method }}</span>
                            </div>
                        </template>
                    </Column>

                    <Column field="amount" header="Monto Cobrado" sortable>
                        <template #body="{data}"><span class="font-bold">{{ formatCurrency(data.amount) }}</span></template>
                    </Column>
                </DataTable>
            </div>

            <!-- TAB: VENTAS CREADAS -->
            <div v-show="activeTab === 'ventas'" class="max-h-[400px] overflow-y-auto">
                <div class="mb-4 text-xs text-blue-600 dark:text-blue-300 bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg flex items-start gap-2 border border-blue-100 dark:border-blue-800">
                    <i class="pi pi-info-circle mt-0.5"></i>
                    <p class="m-0">Esta tabla muestra el valor total de la <strong>mercancía vendida o apartada</strong> durante esta sesión. No representa necesariamente el dinero que ingresó a la caja (ej. si se dejó un artículo a crédito).</p>
                </div>

                <DataTable :value="sortedTransactions" class="p-datatable-sm" responsiveLayout="scroll" @sort="onSort" :sortField="sort.field" :sortOrder="sort.order">
                    <template #empty><div class="text-center py-4 text-gray-500">No hay ventas generadas en esta sesión.</div></template>
                    <Column field="folio" header="Folio" sortable>
                        <template #body="{ data }">
                            <Link :href="route('transactions.show', data.id)" class="text-primary-600 dark:text-primary-400 font-medium hover:underline">
                                {{ data.folio }}
                            </Link>
                        </template>
                    </Column>
                    <Column field="created_at" header="Hora" sortable><template #body="{ data }">{{ formatTime(data.created_at) }}</template></Column>
                    <Column field="user.name" header="Cajero" sortable></Column>
                    <Column field="total" header="Valor de Mercancía" sortable><template #body="{data}"><span class="font-medium">{{ formatCurrency(data.total) }}</span></template></Column>
                </DataTable>
            </div>

        </template>
    </Card>
</template>