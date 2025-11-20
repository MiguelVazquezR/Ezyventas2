<script setup>
import { Head } from '@inertiajs/vue3';
import PatternLock from '@/Components/PatternLock.vue';
import { useTemplateRenderer } from '@/Composables/useTemplateRenderer';
import { computed } from 'vue';

const props = defineProps({
    quote: Object,
    customFieldDefinitions: Array,
    printTemplate: Object, // Plantilla seleccionada (opcional)
});

const { replaceVariables, renderQuoteTable } = useTemplateRenderer();

const print = () => {
    window.print();
};

// --- Helper Data para Plantilla ---
const elements = computed(() => props.printTemplate?.content?.elements || []);
const config = computed(() => props.printTemplate?.content?.config || {});
const pageSizeClass = computed(() => {
    if (!props.printTemplate) return 'max-w-4xl'; // Default
    return config.value.pageSize === 'letter' ? 'w-[21.59cm]' : 'w-[21cm]'; // Letter vs A4
});
const pageHeightClass = computed(() => {
    if (!props.printTemplate) return 'min-h-screen';
    return config.value.pageSize === 'letter' ? 'min-h-[27.94cm]' : 'min-h-[29.7cm]';
});

// --- Helpers Default ---
const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    const userTimezoneOffset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() + userTimezoneOffset).toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' });
};
const getItemType = (itemableType) => {
    if (!itemableType) return 'Servicio';
    return itemableType.includes('Product') ? 'Producto' : 'Servicio';
};
const getFormattedCustomValue = (field, value) => {
    if (value === null || value === undefined) return 'N/A';
    switch (field.type) {
        case 'boolean': return value ? 'Sí' : 'No';
        case 'checkbox': return Array.isArray(value) ? value.join(', ') : value;
        default: return value;
    }
};
</script>

<template>
    <Head :title="`Cotización #${quote.folio}`" />
    
    <!-- Agregamos 'items-start' para evitar centrado vertical que pueda afectar coordenadas relativas -->
    <div class="bg-gray-100 dark:bg-gray-900 min-h-screen p-4 sm:p-8 print:p-0 print:bg-white flex flex-col items-center print:block">
        
        <!-- Botón de acción -->
        <div class="w-full max-w-4xl mb-4 action-buttons flex justify-end print:hidden">
             <Button @click="print" label="Imprimir / Guardar PDF" icon="pi pi-print" severity="warning" />
        </div>

        <!-- ========================================== -->
        <!-- MODO 1: PLANTILLA PERSONALIZADA -->
        <!-- ========================================== -->
        <div v-if="printTemplate" 
             class="bg-white shadow-lg print:shadow-none print:w-full overflow-hidden relative print-content mx-auto"
             :class="[pageSizeClass, pageHeightClass]"
             :style="{ padding: config.margins || '1.5cm', fontFamily: config.fontFamily || 'sans-serif' }">
            
            <div class="w-full h-full relative">
                <template v-for="element in elements" :key="element.id">
                    
                    <!-- Elementos de Flujo (Relative) -->
                    <div v-if="element.data.positionType === 'flow'" class="mb-2">
                        <!-- Texto Rico -->
                        <div v-if="element.type === 'rich_text'" 
                             v-html="replaceVariables(element.data.content, quote)" 
                             class="prose max-w-none text-sm break-words">
                        </div>
                        
                        <!-- Tabla de Cotización -->
                        <div v-if="element.type === 'quote_table'" 
                             v-html="renderQuoteTable(element, quote)">
                        </div>

                        <!-- 2 Columnas -->
                        <div v-if="element.type === 'columns_2'" class="flex" :style="{ gap: element.data.gap }">
                            <div class="flex-1 text-sm break-words" v-html="replaceVariables(element.data.col1, quote)"></div>
                            <div class="flex-1 text-sm break-words" v-html="replaceVariables(element.data.col2, quote)"></div>
                        </div>

                        <!-- Separador -->
                        <div v-if="element.type === 'separator'" 
                             :style="{ 
                                 borderTop: `${element.data.height}px ${element.data.style} ${element.data.color}`, 
                                 margin: `${element.data.margin} 0` 
                             }">
                        </div>
                        
                        <!-- Firma -->
                        <div v-if="element.type === 'signature'" 
                             class="flex flex-col mt-8"
                             :class="`items-${element.data.align || 'center'}`">
                            <div class="border-t border-black pt-1" :style="{ width: element.data.lineWidth }"></div>
                            <span class="text-xs mt-1">{{ replaceVariables(element.data.label, quote) }}</span>
                        </div>
                    </div>

                    <!-- Elementos Absolutos (Absolute) -->
                    <div v-else-if="element.data.positionType === 'absolute'"
                         class="absolute"
                         :style="{ left: element.data.x + 'px', top: element.data.y + 'px' }">
                        
                        <!-- Imagen -->
                         <div v-if="element.type === 'image'" :style="{ width: element.data.width + 'px' }">
                            <img :src="element.data.url" class="w-full h-auto block" />
                        </div>

                        <!-- Figura -->
                        <div v-if="element.type === 'shape'" 
                             :style="{ 
                                 width: element.data.width + 'px', 
                                 height: element.data.height + 'px', 
                                 backgroundColor: element.data.shapeType !== 'star' ? element.data.color : 'transparent', 
                                 opacity: element.data.opacity/100, 
                                 transform: `rotate(${element.data.rotation}deg)`, 
                                 borderRadius: element.data.shapeType === 'circle' ? '50%' : '0' 
                             }">
                             <svg v-if="element.data.shapeType === 'star'" viewBox="0 0 24 24" class="w-full h-full" :style="{ fill: element.data.color }"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                        </div>
                    </div>

                </template>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- MODO 2: PLANTILLA POR DEFECTO (Hardcoded) -->
        <!-- ========================================== -->
        <main v-else class="max-w-4xl w-full bg-white dark:bg-gray-800 p-8 sm:p-12 shadow-lg print-content">
            <!-- Header -->
            <header class="grid grid-cols-2 items-start mb-3">
                <div class="*:m-0">
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

            <!-- Información del Cliente -->
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

            <!-- Tabla de Conceptos -->
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

            <!-- Totales -->
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
            
            <!-- Detalles Adicionales -->
            <section v-if="customFieldDefinitions && customFieldDefinitions.length > 0" class="mb-6">
                <h3 class="text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">Detalles adicionales</h3>
                <div class="grid grid-cols-2 gap-x-8 gap-y-4 text-sm">
                    <template v-for="def in customFieldDefinitions" :key="def.id">
                        <div v-if="quote.custom_fields && quote.custom_fields[def.key]" class="py-2">
                            <span class="font-medium text-gray-500 dark:text-gray-400">{{ def.name }}</span>
                            <div class="mt-1 text-gray-800 dark:text-gray-200">
                                <PatternLock v-if="def.type === 'pattern'" v-model="quote.custom_fields[def.key]" read-only />
                                <span v-else>{{ getFormattedCustomValue(def, quote.custom_fields[def.key]) }}</span>
                            </div>
                        </div>
                    </template>
                </div>
            </section>

            <!-- Notas -->
            <footer v-if="quote.notes" class="border-t pt-6 text-sm text-gray-600 dark:text-gray-400">
                <h4 class="font-semibold mb-2">Notas:</h4>
                <p class="whitespace-pre-wrap">{{ quote.notes }}</p>
            </footer>
        </main>
    </div>
</template>

<style>
@media print {
    @page {
        margin: 0;
    }
    body {
        margin: 0;
        background-color: white !important;
        -webkit-print-color-adjust: exact;
    }
    .action-buttons { display: none; }
    .print-content { 
        box-shadow: none !important; 
        margin: 0 !important;
        width: 100% !important;
        max-width: none !important;
    }
}
.items-start { align-items: flex-start; }
.items-center { align-items: center; }
.items-end { align-items: flex-end; }
</style>