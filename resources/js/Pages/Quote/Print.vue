<script setup>
import { Head } from '@inertiajs/vue3';
import PatternLock from '@/Components/PatternLock.vue'; // <-- 1. Importar

const props = defineProps({
    quote: Object,
    customFieldDefinitions: Array, // <-- 2. Añadir prop
});

const print = () => {
    window.print();
};

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    // Ajuste para la zona horaria
    const date = new Date(dateString);
    const userTimezoneOffset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() + userTimezoneOffset).toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' });
};

// --- 3. Helpers añadidos ---
const getItemType = (itemableType) => {
    if (!itemableType) return 'Servicio';
    return itemableType.includes('Product') ? 'Producto' : 'Servicio';
};

const getFormattedCustomValue = (field, value) => {
    if (value === null || value === undefined) return 'N/A';
    switch (field.type) {
        case 'boolean':
            return value ? 'Sí' : 'No';
        case 'checkbox':
            return Array.isArray(value) ? value.join(', ') : value;
        default:
            return value;
    }
};
// --- Fin Helpers ---
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
            <header class="grid grid-cols-2 items-start mb-3">
                <div class="*:m-0">
                    <!-- Asumiendo que branch.subscription.commercial_name existe -->
                    <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">{{ quote.branch?.subscription?.commercial_name || 'Mi Negocio' }}</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ quote.branch.name }}</p>
                </div>
                <div class="text-right *:m-0">
                    <h2 class="text-2xl font-semibold text-gray-700 dark:text-gray-300">Cotización</h2>
                    <p class="text-gray-500 dark:text-gray-400 font-mono">#{{ quote.folio }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Fecha: {{ formatDate(quote.created_at) }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Vence: {{ formatDate(quote.expiry_date) }}</p>
                </div>
            </header>

            <!-- 4. Información del Cliente/Destinatario (ACTUALIZADA) -->
            <section class="mb-4 *:text-sm">
                <h3 class="font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">Cotizado a:</h3>
                <div class="text-gray-800 dark:text-gray-200 *:m-0">
                    <p class="font-bold">{{ quote.recipient_name }}</p>
                    <p v-if="quote.customer && quote.customer.name !== quote.recipient_name" class="text-gray-500">(Cliente: {{ quote.customer.name }})</p>
                    <p v-if="quote.recipient_phone">{{ quote.recipient_phone }}</p>
                    <p v-if="quote.recipient_email">{{ quote.recipient_email }}</p>
                    <p v-if="quote.shipping_address" class="mt-2 whitespace-pre-wrap">{{ quote.shipping_address }}</p>
                </div>
            </section>

            <!-- 5. Tabla de Conceptos (ACTUALIZADA) -->
            <section class="mb-4">
                <table class="w-full text-left">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="p-2 text-xs font-semibold uppercase text-gray-600 dark:text-gray-300">Descripción</th>
                            <th class="p-2 text-xs font-semibold uppercase text-gray-600 dark:text-gray-300 text-center w-24">Cant.</th>
                            <th class="p-2 text-xs font-semibold uppercase text-gray-600 dark:text-gray-300 text-right w-32">P. Unit.</th>
                            <th class="p-2 text-xs font-semibold uppercase text-gray-600 dark:text-gray-300 text-right w-32">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in quote.items" :key="item.id" class="border-b dark:border-gray-700 text-sm">
                            <td class="p-2 align-top">
                                <div class="font-medium text-gray-800 dark:text-gray-200">{{ item.description }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                    <span class="mr-2">{{ getItemType(item.itemable_type) }}</span>
                                    <span v-if="item.variant_details">({{ Object.values(item.variant_details).join(', ') }})</span>
                                </div>
                            </td>
                            <td class="p-2 align-top text-center">{{ item.quantity }}</td>
                            <td class="p-2 align-top text-right">{{ formatCurrency(item.unit_price) }}</td>
                            <td class="p-2 align-top text-right">{{ formatCurrency(item.line_total) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- 6. Totales (ACTUALIZADOS) -->
            <section class="flex justify-end mb-3">
                <div class="w-full max-w-sm space-y-1 text-sm">
                    <div class="flex justify-between"><span>Subtotal:</span> <span>{{ formatCurrency(quote.subtotal) }}</span></div>
                    <div class="flex justify-between"><span>Descuento:</span> <span class="text-red-500">- {{ formatCurrency(quote.total_discount) }}</span></div>
                    <div class="flex justify-between">
                        <span>Impuestos ({{ quote.tax_type === 'included' ? 'Incluidos' : (quote.tax_rate || 0) + '%' }}):</span>
                        <span>{{ formatCurrency(quote.total_tax) }}</span>
                    </div>
                    <div class="flex justify-between"><span>Envío:</span> <span>{{ formatCurrency(quote.shipping_cost) }}</span></div>
                    <div class="flex justify-between font-bold text-lg border-t pt-2 mt-2"><span>Total:</span> <span>{{ formatCurrency(quote.total_amount) }}</span></div>
                </div>
            </section>
            
            <!-- 7. Detalles Adicionales (NUEVO) -->
            <section v-if="customFieldDefinitions && customFieldDefinitions.length > 0" class="mb-6">
                <h3 class="text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">Detalles adicionales</h3>
                <div class="grid grid-cols-2 gap-x-8 gap-y-4 text-sm">
                    <template v-for="def in customFieldDefinitions" :key="def.id">
                        <div v-if="quote.custom_fields && quote.custom_fields[def.key]" class="py-2">
                            <span class="font-medium text-gray-500 dark:text-gray-400">{{ def.name }}</span>
                            <div class="mt-1 text-gray-800 dark:text-gray-200">
                                <PatternLock v-if="def.type === 'pattern'" v-model="quote.custom_fields[def.key]"
                                    read-only />
                                <span v-else>{{ getFormattedCustomValue(def, quote.custom_fields[def.key]) }}</span>
                            </div>
                        </div>
                    </template>
                </div>
            </section>

            <!-- 8. Notas (ACTUALIZADO) -->
            <footer v-if="quote.notes" class="border-t pt-6 text-sm text-gray-600 dark:text-gray-400">
                <h4 class="font-semibold mb-2">Notas:</h4>
                <p class="whitespace-pre-wrap">{{ quote.notes }}</p>
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