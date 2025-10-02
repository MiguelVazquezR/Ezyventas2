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
    { label: 'Control Financiero', url: route('financial-control.index') },
    { label: 'Historial de Cortes', url: route('cash-register-sessions.index') },
    { label: `Detalle de Corte #${props.session.id}` }
]);

const cashSales = computed(() => 
    props.session.transactions
        .flatMap(t => t.payments)
        .filter(p => p.payment_method === 'efectivo' && p.status === 'completado')
        .reduce((sum, p) => sum + parseFloat(p.amount), 0)
);

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
const formatDate = (dateString) => new Date(dateString).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
const formatTime = (dateString) => new Date(dateString).toLocaleTimeString('es-MX', { hour: 'numeric', minute: '2-digit' });

const paymentMethodIcons = {
    efectivo: { icon: 'pi pi-money-bill', color: 'text-green-500' },
    tarjeta: { icon: 'pi pi-credit-card', color: 'text-blue-500' },
    transferencia: { icon: 'pi pi-globe', color: 'text-purple-500' },
};

const printReport = () => {
    window.open(route('cash-register-sessions.print', props.session.id), '_blank');
};

// --- Lógica de Ordenamiento ---
const sort = ref({
    field: 'created_at', // Campo inicial de ordenamiento
    order: -1, // Orden inicial (1 para asc, -1 para desc)
});

const onSort = (event) => {
    sort.value.field = event.sortField;
    sort.value.order = event.sortOrder;
};

const sortedTransactions = computed(() => {
    const transactions = [...props.session.transactions];
    transactions.sort((a, b) => {
        let valA, valB;
        
        // Asignar valores para la comparación
        if (sort.value.field === 'created_at') {
            valA = new Date(a.created_at);
            valB = new Date(b.created_at);
        } else if (sort.value.field === 'total') {
            valA = parseFloat(a.total);
            valB = parseFloat(b.total);
        } else if (sort.value.field === 'payments') {
            // Para ordenar por método de pago, se crea una cadena ordenada de los métodos.
            valA = a.payments.map(p => p.payment_method).sort().join(', ');
            valB = b.payments.map(p => p.payment_method).sort().join(', ');
        } else {
            valA = a[sort.value.field];
            valB = b[sort.value.field];
        }

        // Comparación
        if (valA < valB) {
            return -1 * sort.value.order;
        }
        if (valA > valB) {
            return 1 * sort.value.order;
        }
        return 0;
    });
    return transactions;
});

</script>

<template>
    <Head :title="`Detalle de Corte #${session.id}`" />
    <AppLayout>
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        
        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mt-4 mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Detalle de Corte de Caja #{{ session.id }}</h1>
                <p class="text-gray-500 dark:text-gray-400 mt-1">Realizado por: {{ session.user.name }} en la caja "{{ session.cash_register.name }}"</p>
            </div>
            <Button 
                label="Imprimir Reporte" 
                icon="pi pi-print" 
                severity="secondary" 
                outlined 
                @click="printReport"
            />
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Columna Principal -->
            <div class="lg:col-span-2 space-y-6">
                <Card>
                    <template #title>Resumen Financiero del Corte</template>
                    <template #content>
                        <ul class="space-y-3 text-sm">
                            <li class="flex justify-between"><span>Fondo Inicial:</span> <span class="font-mono">{{ formatCurrency(session.opening_cash_balance) }}</span></li>
                            <li class="flex justify-between"><span>(+) Ventas en Efectivo:</span> <span class="font-mono text-green-500">{{ formatCurrency(sessionTotals.cash_total) }}</span></li>
                            <li class="flex justify-between"><span>(+) Otros Ingresos:</span> <span class="font-mono text-green-500">{{ formatCurrency(session.cash_movements.filter(m => m.type === 'ingreso').reduce((sum, m) => sum + parseFloat(m.amount), 0)) }}</span></li>
                            <li class="flex justify-between"><span>(-) Egresos / Retiros:</span> <span class="font-mono text-red-500">{{ formatCurrency(session.cash_movements.filter(m => m.type === 'egreso').reduce((sum, m) => sum + parseFloat(m.amount), 0)) }}</span></li>
                            <li class="flex justify-between border-t pt-2 mt-2 font-semibold"><span>Total Esperado en Caja:</span> <span class="font-mono">{{ formatCurrency(session.calculated_cash_total) }}</span></li>
                             <li class="flex justify-between"><span>Total Contado por Cajero:</span> <span class="font-mono">{{ formatCurrency(session.closing_cash_balance) }}</span></li>
                            <li class="flex justify-between font-bold text-base border-t pt-2 mt-2" :class="session.cash_difference != 0 ? (session.cash_difference > 0 ? 'text-green-600' : 'text-red-600') : ''">
                                <span>Diferencia (Sobrante/Faltante):</span> <span class="font-mono">{{ formatCurrency(session.cash_difference) }}</span>
                            </li>
                        </ul>
                        <div v-if="session.notes" class="mt-4 pt-4 border-t">
                            <h4 class="font-semibold">Notas del Cajero:</h4>
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
                                 <Column field="channel" header="Canal" class="capitalize" sortable></Column>
                                 <Column field="total" header="Monto" sortable>
                                     <template #body="{data}">{{ formatCurrency(data.total) }}</template>
                                 </Column>
                                 <Column header="Métodos de Pago" sortable sortField="payments">
                                      <template #body="{ data }">
                                           <div class="flex flex-col gap-1">
                                                <div v-for="payment in data.payments" :key="payment.id" class="flex items-center gap-2">
                                                    <i class="pi" :class="paymentMethodIcons[payment.payment_method].icon + ' ' + paymentMethodIcons[payment.payment_method].color"></i>
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
                                 <span><i class="pi pi-money-bill mr-2 text-green-500"></i>Efectivo</span>
                                 <span class="font-mono font-semibold">{{ formatCurrency(sessionTotals.cash_total) }}</span>
                            </li>
                            <li class="flex justify-between items-center">
                                <span><i class="pi pi-credit-card mr-2 text-blue-500"></i>Tarjeta</span>
                                <span class="font-mono font-semibold">{{ formatCurrency(sessionTotals.card_total) }}</span>
                            </li>
                            <li class="flex justify-between items-center">
                                <span><i class="pi pi-globe mr-2 text-purple-500"></i>Transferencia</span>
                                <span class="font-mono font-semibold">{{ formatCurrency(sessionTotals.transfer_total) }}</span>
                            </li>
                        </ul>
                    </template>
                </Card>
                <Card>
                    <template #title>Movimientos de Efectivo</template>
                    <template #content>
                         <DataTable :value="session.cash_movements" class="p-datatable-sm">
                             <template #empty><div class="text-center py-4">No hubo movimientos.</div></template>
                             <Column field="description" header="Descripción"></Column>
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