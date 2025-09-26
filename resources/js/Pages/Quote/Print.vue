<script setup>
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    quote: Object,
});

const print = () => {
    window.print();
};

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' });
};
</script>

<template>
    <Head :title="`Cotización #${quote.folio}`" />
    <div class="bg-gray-100 dark:bg-gray-900 min-h-screen p-4 sm:p-8 print:p-0 print:bg-white">
        <!-- Botón de acción, se oculta al imprimir -->
        <div class="max-w-4xl mx-auto mb-4 action-buttons">
             <Button @click="print" label="Imprimir / Guardar PDF" icon="pi pi-print" severity="warning" />
        </div>

        <!-- Contenido de la cotización -->
        <main class="max-w-4xl mx-auto bg-white dark:bg-gray-800 p-8 sm:p-12 shadow-lg print-content">
            <!-- Header -->
            <header class="grid grid-cols-2 items-start mb-12">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">{{ quote.branch.subscription.commercial_name }}</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ quote.branch.name }}</p>
                </div>
                <div class="text-right">
                    <h2 class="text-2xl font-semibold text-gray-700 dark:text-gray-300">Cotización</h2>
                    <p class="text-gray-500 dark:text-gray-400 font-mono">#{{ quote.folio }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Fecha: {{ formatDate(quote.created_at) }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Vence: {{ formatDate(quote.expiry_date) }}</p>
                </div>
            </header>

            <!-- Información del Cliente -->
            <section class="mb-10">
                <h3 class="text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">Cotizado a:</h3>
                <div class="text-gray-800 dark:text-gray-200">
                    <p class="font-bold">{{ quote.customer.name }}</p>
                    <p v-if="quote.customer.phone">{{ quote.customer.phone }}</p>
                    <p v-if="quote.customer.email">{{ quote.customer.email }}</p>
                </div>
            </section>

            <!-- Tabla de Conceptos -->
            <section class="mb-10">
                <table class="w-full text-left">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="p-3 text-sm font-semibold uppercase text-gray-600 dark:text-gray-300">Descripción</th>
                            <th class="p-3 text-sm font-semibold uppercase text-gray-600 dark:text-gray-300 text-center w-24">Cant.</th>
                            <th class="p-3 text-sm font-semibold uppercase text-gray-600 dark:text-gray-300 text-right w-32">P. Unit.</th>
                            <th class="p-3 text-sm font-semibold uppercase text-gray-600 dark:text-gray-300 text-right w-32">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in quote.items" :key="item.id" class="border-b dark:border-gray-700">
                            <td class="p-3 align-top">{{ item.description }}</td>
                            <td class="p-3 align-top text-center">{{ item.quantity }}</td>
                            <td class="p-3 align-top text-right">{{ formatCurrency(item.unit_price) }}</td>
                            <td class="p-3 align-top text-right">{{ formatCurrency(item.line_total) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- Totales -->
            <section class="flex justify-end mb-10">
                <div class="w-full max-w-sm space-y-2 text-sm">
                    <div class="flex justify-between"><span>Subtotal:</span> <span>{{ formatCurrency(quote.subtotal) }}</span></div>
                    <div class="flex justify-between"><span>Descuento:</span> <span class="text-red-500">- {{ formatCurrency(quote.total_discount) }}</span></div>
                    <div class="flex justify-between"><span>Impuestos ({{ quote.tax_rate || 0 }}%):</span> <span>{{ formatCurrency(quote.total_tax) }}</span></div>
                    <div class="flex justify-between"><span>Envío:</span> <span>{{ formatCurrency(quote.shipping_cost) }}</span></div>
                    <div class="flex justify-between font-bold text-lg border-t pt-2 mt-2"><span>Total:</span> <span>{{ formatCurrency(quote.total_amount) }}</span></div>
                </div>
            </section>
            
            <!-- Notas -->
            <footer v-if="quote.notes" class="border-t pt-6 text-sm text-gray-600 dark:text-gray-400">
                <h4 class="font-semibold mb-2">Notas:</h4>
                <p>{{ quote.notes }}</p>
            </footer>
        </main>
    </div>
</template>

<style>
@media print {
    .action-buttons {
        display: none;
    }
    .print-content {
        box-shadow: none !important;
    }
    body {
        background-color: white !important;
    }
}
</style>