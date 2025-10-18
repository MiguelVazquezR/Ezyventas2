<script setup>
import { ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    session: Object,
    sessionTotals: Object,
    bankAccountSummary: Array,
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

// --- Lógica para filtrar ingresos (Excluir 'saldo') ---
const filteredIncomeTotals = computed(() => {
    if (!props.sessionTotals) return {};
    const { saldo, ...incomeTotals } = props.sessionTotals;
    return incomeTotals;
});

const totalAllIncome = computed(() => {
    return Object.values(filteredIncomeTotals.value).reduce((sum, total) => sum + parseFloat(total), 0);
});


const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
const formatTime = (dateString) => new Date(dateString).toLocaleTimeString('es-MX', { hour: 'numeric', minute: '2-digit' });

const paymentMethodDetails = {
    efectivo: { name: 'Efectivo', icon: 'pi pi-money-bill', color: 'text-green-600' },
    tarjeta: { name: 'Tarjeta', icon: 'pi pi-credit-card', color: 'text-blue-600' },
    transferencia: { name: 'Transferencia', icon: 'pi pi-arrows-h', color: 'text-orange-500' },
    saldo: { name: 'Saldo de cliente', icon: 'pi pi-wallet', color: 'text-purple-500' },
};

const printReport = () => {
    window.open(route('cash-register-sessions.print', props.session.id), '_blank');
};

const getPaymentsForTransaction = (transactionId) => {
    return (props.session.payments || []).filter(p => p.transaction_id === transactionId);
};

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
        else if (field === 'payments') {
            valA = getPaymentsForTransaction(a.id).map(p => p.payment_method).sort().join(', ');
            valB = getPaymentsForTransaction(b.id).map(p => p.payment_method).sort().join(', ');
        } else {
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
        
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mt-4 mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Detalle de corte de caja #{{ session.id }}</h1>
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
            <div class="lg:col-span-2 space-y-6">
                <Card>
                    <template #title>Resumen financiero del corte</template>
                    <template #content>
                        <ul class="space-y-3 text-sm">
                            <li class="flex justify-between"><span>Fondo inicial:</span> <span class="font-mono">{{ formatCurrency(session.opening_cash_balance) }}</span></li>
                            <li class="flex justify-between"><span>(+) Ventas en efectivo:</span> <span class="font-mono text-green-500">{{ formatCurrency(sessionTotals.efectivo || 0) }}</span></li>
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

                <Card v-if="bankAccountSummary && bankAccountSummary.length > 0">
                    <template #title>Resumen de Cuentas Bancarias</template>
                    <template #content>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm text-left">
                                <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                                    <tr>
                                        <th scope="col" class="px-4 py-3">Cuenta</th>
                                        <th scope="col" class="px-4 py-3 text-right">Saldo Inicial</th>
                                        <th scope="col" class="px-4 py-3 text-right">Saldo Final</th>
                                        <th scope="col" class="px-4 py-3 text-right">Diferencia</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="account in bankAccountSummary" :key="account.id" class="border-b dark:border-gray-700">
                                        <td class="px-4 py-3 font-medium">{{ account.account_name }} ({{ account.bank_name }})</td>
                                        <td class="px-4 py-3 text-right font-mono">{{ formatCurrency(account.initial_balance) }}</td>
                                        <td class="px-4 py-3 text-right font-mono">{{ formatCurrency(account.final_balance) }}</td>
                                        <td class="px-4 py-3 text-right font-mono font-semibold" :class="{'text-green-500': (account.final_balance - account.initial_balance) > 0, 'text-red-500': (account.final_balance - account.initial_balance) < 0}">
                                            {{ formatCurrency(account.final_balance - account.initial_balance) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                </Card>

                <Card>
                    <template #title>Ventas de la Sesión</template>
                    <template #content>
                        <div class="max-h-[350px] overflow-y-auto">
                             <DataTable :value="sortedTransactions" class="p-datatable-sm" responsiveLayout="scroll" @sort="onSort" :sortField="sort.field" :sortOrder="sort.order">
                                 <template #empty><div class="text-center py-4">No hay ventas en esta sesión.</div></template>
                                 <Column field="folio" header="Folio" sortable></Column>
                                 <Column field="created_at" header="Hora" sortable><template #body="{ data }">{{ formatTime(data.created_at) }}</template></Column>
                                 <Column field="user.name" header="Cajero" sortable></Column>
                                 <Column field="total" header="Monto" sortable><template #body="{data}">{{ formatCurrency(data.total) }}</template></Column>
                                 <Column header="Métodos de Pago" sortable sortField="payments">
                                     <template #body="{ data }">
                                         <div class="flex flex-col gap-1">
                                             <div v-for="payment in getPaymentsForTransaction(data.id)" :key="payment.id">
                                                <div class="flex items-center gap-2">
                                                    <i class="pi" :class="paymentMethodDetails[payment.payment_method]?.icon + ' ' + paymentMethodDetails[payment.payment_method]?.color"></i>
                                                    <span class="text-xs capitalize">{{ paymentMethodDetails[payment.payment_method]?.name || payment.payment_method }}</span>
                                                </div>
                                                <div v-if="(payment.payment_method === 'tarjeta' || payment.payment_method === 'transferencia') && payment.bank_account" class="text-xs text-gray-500 dark:text-gray-400 pl-5">
                                                    ↳ {{ payment.bank_account.account_name }}
                                                </div>
                                             </div>
                                        </div>
                                     </template>
                                 </Column>
                             </DataTable>
                        </div>
                    </template>
                </Card>
            </div>
            
            <div class="lg:col-span-1 space-y-6">
                <Card>
                    <template #title>Desglose de Ingresos</template>
                    <template #content>
                        <ul v-if="Object.keys(filteredIncomeTotals).length > 0" class="space-y-3 text-sm">
                             <li v-for="(total, method) in filteredIncomeTotals" :key="method" class="flex justify-between items-center">
                                 <span><i class="pi mr-2" :class="paymentMethodDetails[method]?.icon + ' ' + paymentMethodDetails[method]?.color"></i>{{ paymentMethodDetails[method]?.name || method }}</span>
                                 <span class="font-mono font-semibold">{{ formatCurrency(total) }}</span>
                            </li>
                            <li class="flex justify-between items-center border-t pt-3 mt-3 font-bold">
                                 <span><i class="pi pi-chart-bar mr-2"></i>Total de Ingresos</span>
                                 <span class="font-mono">{{ formatCurrency(totalAllIncome) }}</span>
                            </li>
                        </ul>
                         <p v-else class="text-sm text-gray-500 text-center">No se registraron ingresos en esta sesión.</p>
                    </template>
                </Card>
                <Card>
                    <template #title>Movimientos de Efectivo</template>
                    <template #content>
                         <DataTable :value="safeCashMovements" class="p-datatable-sm">
                             <template #empty><div class="text-center py-4">No hubo movimientos.</div></template>
                             <Column field="description" header="Descripción"></Column>
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