<script setup>
import { ref, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    session: Object,
    sessionTotals: Object,
});

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Historial de Cortes', url: route('cash-register-sessions.index') },
    { label: `Detalle de Corte #${props.session.id}` }
]);

const totalInflows = computed(() => {
    if (!props.session?.cash_movements) return 0;
    return props.session.cash_movements
        .filter(m => m.type === 'ingreso')
        .reduce((sum, m) => sum + parseFloat(m.amount), 0);
});

const totalOutflows = computed(() => {
    if (!props.session?.cash_movements) return 0;
    return props.session.cash_movements
        .filter(m => m.type === 'egreso')
        .reduce((sum, m) => sum + parseFloat(m.amount), 0);
});

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
const formatDate = (dateString) => new Date(dateString).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
const formatTime = (dateString) => new Date(dateString).toLocaleTimeString('es-MX', { hour: 'numeric', minute: '2-digit' });

const paymentMethodIcons = {
    efectivo: { icon: 'pi pi-money-bill', color: 'text-green-600' },
    tarjeta: { icon: 'pi pi-credit-card', color: 'text-blue-600' },
    transferencia: { icon: 'pi pi-arrows-h', color: 'text-orange-500' },
    saldo: { icon: 'pi pi-wallet', color: 'text-purple-500' },
};

const printReport = () => {
    window.open(route('cash-register-sessions.print', props.session.id), '_blank');
};

const getPaymentsForTransaction = (transactionId) => {
    return (props.session.payments || []).filter(p => p.transaction_id === transactionId);
};

// Lógica de Ordenamiento
const sort = ref({
    field: 'created_at',
    order: -1,
});
const onSort = (event) => {
    sort.value.field = event.sortField;
    sort.value.order = event.sortOrder;
};

const sortedTransactions = computed(() => {
    if (!props.session?.transactions) return [];

    return [...props.session.transactions].sort((a, b) => {
        let valA, valB;
        const field = sort.value.field;

        if (field === 'created_at') {
            valA = new Date(a.created_at);
            valB = new Date(b.created_at);
        } else if (field === 'total') {
            valA = parseFloat(a.total);
            valB = parseFloat(b.total);
        } else if (field === 'payments') {
            valA = getPaymentsForTransaction(a.id).map(p => p.payment_method).sort().join(', ');
            valB = getPaymentsForTransaction(b.id).map(p => p.payment_method).sort().join(', ');
        } else {
            // Acceso anidado seguro, por ejemplo 'user.name'
            valA = field.split('.').reduce((o, i) => o?.[i], a);
            valB = field.split('.').reduce((o, i) => o?.[i], b);
        }

        if (valA < valB) return -1 * sort.value.order;
        if (valA > valB) return 1 * sort.value.order;
        return 0;
    });
});

const safeCashMovements = computed(() => props.session?.cash_movements || []);
</script>

<template>
    <Head :title="`Detalle de Corte #${session.id}`" />
    <AppLayout>
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        
        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mt-4 mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Detalle de corte de caja #{{ session.id }}</h1>
                <!-- CORRECCIÓN: Se usa 'opener.name' y se muestra la lista de participantes -->
                <div class="flex items-center gap-4 mt-1">
                    <p class="text-gray-500 dark:text-gray-400 m-0">
                        Abierto por: <span class="font-semibold">{{ session.opener.name }}</span> en la caja "{{ session.cash_register.name }}"
                    </p>
                    <div class="flex items-center gap-2">
                        <span class="text-gray-500 dark:text-gray-400">Participantes:</span>
                        <AvatarGroup>
                            <Avatar v-for="user in session.users" :key="user.id" :label="user.name.charAt(0).toUpperCase()" v-tooltip.bottom="user.name" shape="circle" />
                        </AvatarGroup>
                    </div>
                </div>
            </div>
            <Button 
                label="Imprimir reporte" 
                icon="pi pi-print" 
                severity="secondary" 
                outlined 
                @click="printReport"
                class="mt-4 sm:mt-0"
            />
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Columna Principal -->
            <div class="lg:col-span-2 space-y-6">
                <Card>
                    <template #title>Resumen financiero del corte</template>
                    <template #content>
                        <ul class="space-y-3 text-sm">
                            <li class="flex justify-between"><span>Fondo inicial:</span> <span class="font-mono">{{ formatCurrency(session.opening_cash_balance) }}</span></li>
                            <li class="flex justify-between"><span>(+) Ventas en efectivo:</span> <span class="font-mono text-green-500">{{ formatCurrency(sessionTotals.cash_total) }}</span></li>
                            <li class="flex justify-between"><span>(+) Otros ingresos:</span> <span class="font-mono text-green-500">{{ formatCurrency(totalInflows) }}</span></li>
                            <li class="flex justify-between"><span>(-) Egresos / retiros:</span> <span class="font-mono text-red-500">{{ formatCurrency(totalOutflows) }}</span></li>
                            <li class="flex justify-between border-t pt-2 mt-2 font-semibold"><span>Total esperado en caja:</span> <span class="font-mono">{{ formatCurrency(session.calculated_cash_total) }}</span></li>
                             <li class="flex justify-between"><span>Total contado por cajero:</span> <span class="font-mono">{{ formatCurrency(session.closing_cash_balance) }}</span></li>
                            <li class="flex justify-between font-bold text-base border-t pt-2 mt-2" :class="session.cash_difference != 0 ? (session.cash_difference > 0 ? 'text-green-600' : 'text-red-600') : ''">
                                <span>Diferencia (sobrante / faltante):</span> <span class="font-mono">{{ formatCurrency(session.cash_difference) }}</span>
                            </li>
                        </ul>
                        <div v-if="session.notes" class="mt-4 pt-4 border-t">
                            <h4 class="font-semibold text-base">Notas del cajero:</h4>
                            <p class="text-sm text-gray-600 dark:text-gray-400 italic mt-1">"{{ session.notes }}"</p>
                        </div>
                    </template>
                </Card>
                <Card>
                    <template #title>Transacciones de la Sesión</template>
                    <template #content>
                        <div class="max-h-[350px] overflow-y-auto">
                             <DataTable 
                                :value="sortedTransactions" 
                                class="p-datatable-sm" 
                                responsiveLayout="scroll"
                                @sort="onSort"
                                :sortField="sort.field"
                                :sortOrder="sort.order"
                             >
                                 <template #empty><div class="text-center py-4">No hay transacciones en esta sesión.</div></template>
                                 <Column field="folio" header="Folio" sortable></Column>
                                 <Column field="created_at" header="Hora" sortable>
                                     <template #body="{ data }">{{ formatTime(data.created_at) }}</template>
                                 </Column>
                                 <!-- MEJORA: Se muestra el nombre del usuario de la transacción -->
                                 <Column field="user.name" header="Cajero" sortable></Column>
                                 <Column field="total" header="Monto" sortable>
                                      <template #body="{data}">{{ formatCurrency(data.total) }}</template>
                                 </Column>
                                 <Column header="Métodos de Pago" sortable sortField="payments">
                                     <template #body="{ data }">
                                         <!-- CORRECCIÓN: Se usa el helper para obtener los pagos -->
                                         <div class="flex flex-col gap-1">
                                             <div v-for="payment in getPaymentsForTransaction(data.id)" :key="payment.id" class="flex items-center gap-2">
                                                 <i class="pi" :class="paymentMethodIcons[payment.payment_method]?.icon + ' ' + paymentMethodIcons[payment.payment_method]?.color"></i>
                                                 <span class="text-xs capitalize">{{ payment.payment_method }}</span>
                                             </div>
                                        </div>
                                     </template>
                                 </Column>
                             </DataTable>
                        </div>
                    </template>
                </Card>
            </div>
            <!-- Columna Derecha -->
            <div class="lg:col-span-1 space-y-6">
                <Card>
                    <template #title>Desglose de Ingresos</template>
                    <template #content>
                        <ul class="space-y-3 text-sm">
                             <li class="flex justify-between items-center">
                                 <span><i class="pi pi-money-bill mr-2 text-green-600"></i>Efectivo</span>
                                 <span class="font-mono font-semibold">{{ formatCurrency(sessionTotals.cash_total) }}</span>
                            </li>
                            <li class="flex justify-between items-center">
                                <span><i class="pi pi-credit-card mr-2 text-blue-600"></i>Tarjeta</span>
                                <span class="font-mono font-semibold">{{ formatCurrency(sessionTotals.card_total) }}</span>
                            </li>
                            <li class="flex justify-between items-center">
                                <span><i class="pi pi-arrows-h mr-2 text-orange-500"></i>Transferencia</span>
                                <span class="font-mono font-semibold">{{ formatCurrency(sessionTotals.transfer_total) }}</span>
                            </li>
                            <li class="flex justify-between items-center">
                                <span><i class="pi pi-wallet mr-2 text-purple-500"></i>Saldo de cliente</span>
                                <span class="font-mono font-semibold">{{ formatCurrency(sessionTotals.balance_total) }}</span>
                            </li>
                        </ul>
                    </template>
                </Card>
                <Card>
                    <template #title>Movimientos de Efectivo</template>
                    <template #content>
                         <DataTable :value="safeCashMovements" class="p-datatable-sm">
                             <template #empty><div class="text-center py-4">No hubo movimientos.</div></template>
                             <Column field="description" header="Descripción"></Column>
                             <!-- MEJORA: Se muestra el nombre del usuario del movimiento -->
                             <Column field="user.name" header="Realizado por"></Column>
                             <Column field="amount" header="Monto">
                               <template #body="{data}"><span :class="data.type === 'ingreso' ? 'text-green-500' : 'text-red-500'">{{ formatCurrency(data.amount) }}</span></template>
                             </Column>
                         </DataTable>
                    </template>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>