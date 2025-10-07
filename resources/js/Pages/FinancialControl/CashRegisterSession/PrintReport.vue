<script setup>
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    session: Object,
    sessionTotals: Object,
});

const print = () => {
    window.print();
};

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
const formatDate = (dateString) => new Date(dateString).toLocaleString('es-MX', { dateStyle: 'long', timeStyle: 'short' });

const paymentMethodIcons = {
    efectivo: { icon: 'pi pi-money-bill', color: 'text-[#37672B]' },
    tarjeta: { icon: 'pi pi-credit-card', color: 'text-[#063C53]' },
    transferencia: { icon: 'pi pi-arrows-h', color: 'text-[#D2D880]' },
    saldo: { icon: 'pi pi-wallet', color: 'text-purple-500' },
};
</script>

<template>
    <Head :title="`Reporte de Corte #${session.id}`" />
    <div class="bg-gray-100 min-h-screen p-4 sm:p-8 print:p-0 print:bg-white">
        <!-- Botón de acción, se oculta al imprimir -->
        <div class="max-w-4xl mx-auto mb-4 print:hidden">
             <Button @click="print" label="Imprimir / Guardar PDF" icon="pi pi-print" severity="warning" />
        </div>

        <!-- Contenido del reporte -->
        <main class="max-w-4xl mx-auto bg-white p-8 sm:p-12 shadow-lg print:shadow-none">
            <!-- Header -->
            <header class="grid grid-cols-2 items-start mb-12">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800">{{ session.cash_register.branch.subscription.commercial_name }}</h1>
                    <p class="text-sm text-gray-500">{{ session.cash_register.branch.name }}</p>
                </div>
                <div class="text-right">
                    <h2 class="text-2xl font-semibold text-gray-700">Reporte de Corte de Caja</h2>
                    <p class="text-gray-500 font-mono">Sesión #{{ session.id }}</p>
                    <p class="text-sm text-gray-500 mt-2">Caja: {{ session.cash_register.name }}</p>
                    <p class="text-sm text-gray-500">Cajero: {{ session.user.name }}</p>
                </div>
            </header>

            <!-- Resumen Financiero -->
            <section class="mb-10 bg-gray-50 p-6 rounded-lg">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Resumen Financiero</h3>
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div class="space-y-2">
                        <div class="flex justify-between"><span>Apertura:</span><span class="font-mono">{{ formatDate(session.opened_at) }}</span></div>
                        <div class="flex justify-between"><span>Cierre:</span><span class="font-mono">{{ formatDate(session.closed_at) }}</span></div>
                    </div>
                    <div class="space-y-2">
                        <div class="flex justify-between"><span>Fondo Inicial:</span> <span class="font-mono">{{ formatCurrency(session.opening_cash_balance) }}</span></div>
                        <div class="flex justify-between"><span>Ventas en Efectivo:</span> <span class="font-mono text-green-600">+ {{ formatCurrency(sessionTotals.cash_total) }}</span></div>
                        <div class="flex justify-between"><span>Total Esperado:</span> <span class="font-mono font-semibold">{{ formatCurrency(session.calculated_cash_total) }}</span></div>
                        <div class="flex justify-between"><span>Total Contado:</span> <span class="font-mono font-semibold">{{ formatCurrency(session.closing_cash_balance) }}</span></div>
                        <div class="flex justify-between font-bold" :class="session.cash_difference >= 0 ? 'text-green-600' : 'text-red-600'"><span>Diferencia:</span> <span class="font-mono">{{ formatCurrency(session.cash_difference) }}</span></div>
                    </div>
                </div>
            </section>

            <!-- Desglose -->
            <section class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-10">
                <div>
                    <h3 class="font-semibold border-b pb-2 mb-3">Desglose de Ingresos</h3>
                    <ul class="space-y-2 text-sm">
                        <li class="flex justify-between items-center"><span><i class="pi pi-money-bill mr-2 text-[#37672B]"></i>Efectivo</span><span class="font-mono">{{ formatCurrency(sessionTotals.cash_total) }}</span></li>
                        <li class="flex justify-between items-center"><span><i class="pi pi-credit-card mr-2 text-[#063C53]"></i>Tarjeta</span><span class="font-mono">{{ formatCurrency(sessionTotals.card_total) }}</span></li>
                        <li class="flex justify-between items-center"><span><i class="pi pi-arrows-h mr-2 text-[#D2D880]"></i>Transferencia</span><span class="font-mono">{{ formatCurrency(sessionTotals.transfer_total) }}</span></li>
                        <li class="flex justify-between items-center"><span><i class="pi pi-wallet mr-2 text-purple-500"></i>Saldo de cliente</span><span class="font-mono">{{ formatCurrency(sessionTotals.balance_total) }}</span></li>
                    </ul>
                </div>
                 <div>
                    <h3 class="font-semibold border-b pb-2 mb-3">Movimientos de Efectivo</h3>
                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-for="movement in session.cash_movements" :key="movement.id">
                                <td class="py-1 pr-2">{{ movement.description }}</td>
                                <td class="py-1 text-right font-mono" :class="movement.type === 'ingreso' ? 'text-green-600' : 'text-red-500'">{{ formatCurrency(movement.amount) }}</td>
                            </tr>
                            <tr v-if="session.cash_movements.length === 0"><td colspan="2" class="py-4 text-center text-gray-400">Sin movimientos.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
            
            <!-- Notas -->
            <footer v-if="session.notes" class="border-t pt-6 text-sm text-gray-600">
                <h4 class="font-semibold mb-2">Notas del Cajero:</h4>
                <p class="whitespace-pre-wrap">{{ session.notes }}</p>
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
}
</style>