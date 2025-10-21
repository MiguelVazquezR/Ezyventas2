<script setup>
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    session: Object,
    sessionTotals: Object,
    bankAccountSummary: Array,
});

const print = () => {
    window.print();
};

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
const formatDate = (dateString) => new Date(dateString).toLocaleString('es-MX', { dateStyle: 'long', timeStyle: 'short' });
const formatTime = (dateString) => new Date(dateString).toLocaleTimeString('es-MX', { hour: 'numeric', minute: '2-digit' });

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

const participantNames = computed(() => {
    if (!props.session?.users) return '';
    return props.session.users.map(u => u.name).join(', ');
});

// --- INICIO DE LA MEJORA: Lógica para filtrar ingresos ---

// 1. Se crea una computada que excluye el método de pago 'saldo'.
const filteredIncomeTotals = computed(() => {
    if (!props.sessionTotals) return {};
    const { saldo, ...incomeTotals } = props.sessionTotals; // Se desestructura para quitar 'saldo'
    return incomeTotals;
});

// 2. El total de ingresos ahora se calcula sobre los valores filtrados.
const totalAllIncome = computed(() => {
    return Object.values(filteredIncomeTotals.value).reduce((sum, total) => sum + parseFloat(total), 0);
});

// --- FIN DE LA MEJORA ---


const getPaymentsForTransaction = (transactionId) => {
    return (props.session.payments || []).filter(p => p.transaction_id === transactionId);
};

const paymentMethodDetails = {
    efectivo: { name: 'Efectivo', icon: 'pi pi-money-bill', color: 'text-green-600' },
    tarjeta: { name: 'Tarjeta', icon: 'pi pi-credit-card', color: 'text-blue-600' },
    transferencia: { name: 'Transferencia', icon: 'pi pi-arrows-h', color: 'text-orange-500' },
    saldo: { name: 'Saldo de cliente', icon: 'pi pi-wallet', color: 'text-purple-500' },
};

</script>

<template>
    <Head :title="`Reporte de Corte #${session.id}`" />
    <div class="bg-gray-100 min-h-screen p-4 sm:p-8 print:p-0 print:bg-white">
        <div class="max-w-4xl mx-auto mb-4 print:hidden">
             <Button @click="print" label="Imprimir / Guardar PDF" icon="pi pi-print" severity="warning" />
        </div>

        <main class="max-w-4xl mx-auto bg-white p-8 sm:p-12 shadow-lg print:shadow-none">
            <header class="grid grid-cols-2 items-start mb-12">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800">{{ session.cash_register.branch.subscription.commercial_name }}</h1>
                    <p class="text-sm text-gray-500 m-0">{{ session.cash_register.branch.name }}</p>
                </div>
                <div class="text-right">
                    <h2 class="text-2xl font-semibold text-gray-700">Reporte de Corte de Caja</h2>
                    <p class="text-gray-500 font-mono m-0">Sesión #{{ session.id }}</p>
                    <p class="text-sm text-gray-500 m-0">Caja: {{ session.cash_register.name }}</p>
                    <p class="text-sm text-gray-500 m-0">Abrió sesión: {{ session.opener.name }}</p>
                </div>
            </header>

            <section class="mb-6">
                <h4 class="font-semibold border-b pb-2 mb-3 text-lg">Participantes de la Sesión</h4>
                <p class="text-sm text-gray-700 m-0">{{ participantNames }}</p>
            </section>

            <section class="mb-10 bg-gray-50 p-6 rounded-lg">
                <h4 class="text-lg font-semibold text-gray-800 mb-4">Resumen financiero</h4>
                <div class="grid grid-cols-2 gap-x-8 gap-y-4 text-sm">
                    <div class="space-y-2">
                        <div class="flex justify-between"><span>Apertura:</span><span class="font-mono">{{ formatDate(session.opened_at) }}</span></div>
                        <div class="flex justify-between"><span>Cierre:</span><span class="font-mono">{{ formatDate(session.closed_at) }}</span></div>
                    </div>
                    <div class="space-y-2">
                        <div class="flex justify-between"><span>Fondo Inicial:</span> <span class="font-mono">{{ formatCurrency(session.opening_cash_balance) }}</span></div>
                        <div class="flex justify-between"><span>(+) Ventas en efectivo:</span> <span class="font-mono text-green-600">+ {{ formatCurrency(sessionTotals.efectivo || 0) }}</span></div>
                        <div class="flex justify-between"><span>(+) Otros ingresos:</span> <span class="font-mono text-green-600">+ {{ formatCurrency(totalInflows) }}</span></div>
                        <div class="flex justify-between"><span>(-) Egresos / Retiros:</span> <span class="font-mono text-red-500">- {{ formatCurrency(totalOutflows) }}</span></div>
                        <div class="flex justify-between font-semibold border-t pt-2 mt-2"><span>Total Esperado:</span> <span class="font-mono">{{ formatCurrency(session.calculated_cash_total) }}</span></div>
                        <div class="flex justify-between"><span>Total contado:</span> <span class="font-mono">{{ formatCurrency(session.closing_cash_balance) }}</span></div>
                        <div class="flex justify-between font-bold" :class="session.cash_difference >= 0 ? 'text-green-600' : 'text-red-600'"><span>Diferencia:</span> <span class="font-mono">{{ formatCurrency(session.cash_difference) }}</span></div>
                    </div>
                </div>
            </section>

            <section class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-10">
                <div>
                    <h4 class="font-semibold border-b pb-2 mb-3">Desglose de Ingresos</h4>
                    <!-- 3. La lista ahora itera sobre los totales filtrados -->
                    <ul class="space-y-2 text-sm">
                        <li v-for="(total, method) in filteredIncomeTotals" :key="method" class="flex justify-between items-center">
                            <span><i class="pi mr-2" :class="paymentMethodDetails[method]?.icon + ' ' + paymentMethodDetails[method]?.color"></i>{{ paymentMethodDetails[method]?.name || method }}</span>
                            <span class="font-mono">{{ formatCurrency(total) }}</span>
                        </li>
                        <li class="flex justify-between items-center border-t pt-2 mt-2 font-bold">
                            <span><i class="pi pi-chart-bar mr-2"></i>Total de Ingresos</span>
                            <span class="font-mono">{{ formatCurrency(totalAllIncome) }}</span>
                        </li>
                    </ul>
                </div>
                 <div>
                    <h4 class="font-semibold border-b pb-2 mb-3">Movimientos de Efectivo</h4>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b">
                                <th class="text-left font-semibold pb-2">Descripción</th>
                                <th class="text-left font-semibold pb-2">Realizado por</th>
                                <th class="text-right font-semibold pb-2">Monto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="movement in session.cash_movements" :key="movement.id">
                                <td class="py-1 pr-2">{{ movement.description }}</td>
                                <td class="py-1 pr-2">{{ movement.user?.name }}</td>
                                <td class="py-1 text-right font-mono" :class="movement.type === 'ingreso' ? 'text-green-600' : 'text-red-500'">{{ formatCurrency(movement.amount) }}</td>
                            </tr>
                            <tr v-if="!session.cash_movements || session.cash_movements.length === 0"><td colspan="3" class="py-4 text-center text-gray-400">Sin movimientos.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="bankAccountSummary && bankAccountSummary.length > 0" class="mb-10">
                <h4 class="font-semibold border-b pb-2 mb-3 text-lg">Resumen de Cuentas Bancarias</h4>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="text-left font-semibold py-2">Cuenta</th>
                            <th class="text-right font-semibold py-2">Saldo Inicial</th>
                            <th class="text-right font-semibold py-2">Saldo Final</th>
                            <th class="text-right font-semibold py-2">Diferencia</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="account in bankAccountSummary" :key="account.id">
                            <td class="py-2 pr-2 font-medium">{{ account.account_name }} ({{ account.bank_name }})</td>
                            <td class="py-2 text-right font-mono">{{ formatCurrency(account.initial_balance) }}</td>
                            <td class="py-2 text-right font-mono">{{ formatCurrency(account.final_balance) }}</td>
                            <td class="py-2 text-right font-mono font-semibold" :class="{'text-green-600': (account.final_balance - account.initial_balance) > 0, 'text-red-600': (account.final_balance - account.initial_balance) < 0}">
                                {{ formatCurrency(account.final_balance - account.initial_balance) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

             <section class="mb-10">
                <h4 class="font-semibold border-b pb-2 mb-3 text-lg">Ventas de la Sesión</h4>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="text-left font-semibold py-2">Folio</th>
                            <th class="text-left font-semibold py-2">Hora</th>
                            <th class="text-left font-semibold py-2">Cajero</th>
                            <th class="text-left font-semibold py-2">Pago</th>
                            <th class="text-right font-semibold py-2">Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="transaction in session.transactions" :key="transaction.id" class="border-b">
                            <td class="py-2 pr-2">{{ transaction.folio }}</td>
                            <td class="py-2 pr-2">{{ formatTime(transaction.created_at) }}</td>
                            <td class="py-2 pr-2">{{ transaction.user.name }}</td>
                            <td class="py-2 pr-2">
                                <div v-for="payment in getPaymentsForTransaction(transaction.id)" :key="payment.id" class="text-xs">
                                    <p class="m-0 capitalize">{{ payment.payment_method }}</p>
                                    <p v-if="payment.bank_account" class="m-0 text-gray-500 pl-2">↳ {{ payment.bank_account.account_name }}</p>
                                </div>
                            </td>
                            <td class="py-2 text-right font-mono">{{ formatCurrency(transaction.total) }}</td>
                        </tr>
                        <tr v-if="!session.transactions || session.transactions.length === 0"><td colspan="5" class="py-4 text-center text-gray-400">Sin ventas.</td></tr>
                    </tbody>
                </table>
            </section>
            
            <footer v-if="session.notes" class="border-t pt-6 text-sm text-gray-600">
                <h4 class="font-semibold mb-2">Notas del Corte:</h4>
                <p class="whitespace-pre-wrap m-0">{{ session.notes }}</p>
            </footer>
        </main>
    </div>
</template>

<style>
@media print {
    .print\:hidden { display: none; }
    .print\:p-0 { padding: 0 !important; }
    .print\:bg-white { background-color: white !important; }
    .print\:shadow-none { box-shadow: none !important; }
    body { -webkit-print-color-adjust: exact; }
}
</style>