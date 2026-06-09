<script setup>
import { Head } from '@inertiajs/vue3';
import PatternLock from '@/Components/PatternLock.vue';
import { useTemplateRenderer } from '@/Composables/useTemplateRenderer';
import { computed } from 'vue';

const props = defineProps({
    serviceOrder: Object,
    customFieldDefinitions: Array,
    printTemplate: Object,
});

const { replaceVariables, renderQuoteTable } = useTemplateRenderer();

const print = () => {
    window.print();
};

// --- Helper Data para Plantilla ---
const elements = computed(() => props.printTemplate?.content?.elements || []);
const config = computed(() => props.printTemplate?.content?.config || {});
const pageSizeClass = computed(() => {
    if (!props.printTemplate) return 'max-w-4xl';
    return config.value.pageSize === 'letter' ? 'w-[21.59cm]' : 'w-[21cm]';
});
const pageHeightClass = computed(() => {
    if (!props.printTemplate) return 'min-h-screen';
    return config.value.pageSize === 'letter' ? 'min-h-[27.94cm]' : 'min-h-[29.7cm]';
});

// --- Helpers ---
const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
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

const statusLabel = computed(() => {
    const map = {
        pendiente: 'Pendiente',
        en_progreso: 'En progreso',
        esperando_refaccion: 'Esperando refacción',
        terminado: 'Terminado',
        entregado: 'Entregado',
        cancelado: 'Cancelado',
    };
    return map[props.serviceOrder.status] || props.serviceOrder.status;
});
</script>

<template>
    <Head :title="`Recibo de Servicio #${serviceOrder.folio || serviceOrder.id}`" />

    <div class="bg-gray-100 dark:bg-gray-900 min-h-screen p-4 sm:p-8 print:p-0 print:bg-white print:min-h-0 flex flex-col items-center print:block">

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

            <div class="relative w-full h-full">
                <!-- CAPA 1: FONDO -->
                <div class="absolute inset-0 z-0 pointer-events-none">
                    <template v-for="element in elements" :key="'abs-' + element.id">
                        <div v-if="element.data.positionType === 'absolute'"
                            class="absolute"
                            :style="{ left: element.data.x + 'px', top: element.data.y + 'px' }">
                            <div v-if="element.type === 'image'" :style="{ width: element.data.width + 'px' }">
                                <img :src="element.data.url" class="w-full h-auto block" />
                            </div>
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

                <!-- CAPA 2: CONTENIDO RELATIVO -->
                <div class="relative z-10">
                    <template v-for="element in elements" :key="'flow-' + element.id">
                        <div v-if="element.data.positionType === 'flow' || element.data.positionType !== 'absolute'" class="mb-2 relative">
                            <!-- Texto Rico -->
                            <div v-if="element.type === 'rich_text'"
                                v-html="replaceVariables(element.data.content, serviceOrder, 'service_order')"
                                class="prose max-w-none text-sm break-words">
                            </div>

                            <!-- Tabla de Conceptos -->
                            <div v-if="element.type === 'quote_table'"
                                v-html="renderQuoteTable(element.data, serviceOrder, 'service_order')">
                            </div>

                            <!-- 2 Columnas -->
                            <div v-if="element.type === 'columns_2'" class="flex" :style="{ gap: element.data.gap }">
                                <div class="flex-1 text-sm break-words" v-html="replaceVariables(element.data.col1, serviceOrder, 'service_order')"></div>
                                <div class="flex-1 text-sm break-words" v-html="replaceVariables(element.data.col2, serviceOrder, 'service_order')"></div>
                            </div>

                            <!-- Separador -->
                            <div v-if="element.type === 'separator'"
                                :style="{
                                    borderTop: `${element.data.height}px ${element.data.style} ${element.data.color}`,
                                    margin: `${element.data.margin} 0`
                                }">
                            </div>

                            <!-- Espaciado -->
                            <div v-if="element.type === 'spacer'" :style="{ height: element.data.height + 'px' }"></div>

                            <!-- Firma -->
                            <div v-if="element.type === 'signature'"
                                class="flex flex-col mt-8"
                                :class="`items-${element.data.align || 'center'}`">
                                <div class="border-t border-black pt-1" :style="{ width: element.data.lineWidth }"></div>
                                <span class="text-xs mt-1">{{ replaceVariables(element.data.label, serviceOrder, 'service_order') }}</span>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- MODO 2: PLANTILLA POR DEFECTO (Sin plantilla) -->
        <!-- ========================================== -->
        <div v-else class="bg-white shadow-lg print:shadow-none print:w-full max-w-4xl w-full p-8 print:p-4 print-content mx-auto">

            <!-- Header -->
            <div class="border-b-2 border-gray-800 pb-4 mb-6">
                <div class="flex justify-between items-start">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900 m-0">Recibo de Servicio</h1>
                        <p class="text-sm text-gray-600 mt-1 m-0">#{{ serviceOrder.folio || serviceOrder.id }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-gray-600 m-0">Fecha: {{ formatDate(serviceOrder.received_at) }}</p>
                        <p class="text-sm text-gray-600 m-0">Estatus: {{ statusLabel }}</p>
                    </div>
                </div>
            </div>

            <!-- Datos del Cliente y Equipo -->
            <div class="grid grid-cols-2 gap-6 mb-6">
                <div>
                    <h2 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 mb-2">Cliente</h2>
                    <p class="text-sm font-medium text-gray-900 m-0">{{ serviceOrder.customer?.name || serviceOrder.customer_name || 'Público general' }}</p>
                    <p v-if="serviceOrder.customer?.phone" class="text-xs text-gray-500 m-0">{{ serviceOrder.customer.phone }}</p>
                    <p v-if="serviceOrder.customer?.email" class="text-xs text-gray-500 m-0">{{ serviceOrder.customer.email }}</p>
                </div>
                <div>
                    <h2 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 mb-2">Equipo</h2>
                    <p class="text-sm font-medium text-gray-900 m-0">{{ serviceOrder.item_description }}</p>
                    <p v-if="serviceOrder.reported_problems" class="text-xs text-gray-500 mt-1 m-0 italic">{{ serviceOrder.reported_problems }}</p>
                </div>
            </div>

            <!-- Tabla de Conceptos -->
            <table class="w-full border-collapse mb-6">
                <thead>
                    <tr class="border-b-2 border-gray-800">
                        <th class="text-left py-2 text-[10px] uppercase tracking-widest font-bold text-gray-500">Tipo</th>
                        <th class="text-left py-2 text-[10px] uppercase tracking-widest font-bold text-gray-500">Descripción</th>
                        <th class="text-center py-2 text-[10px] uppercase tracking-widest font-bold text-gray-500">Cant.</th>
                        <th class="text-right py-2 text-[10px] uppercase tracking-widest font-bold text-gray-500">Precio Unit.</th>
                        <th class="text-right py-2 text-[10px] uppercase tracking-widest font-bold text-gray-500">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in serviceOrder.items" :key="item.id" class="border-b border-gray-200">
                        <td class="py-2 text-xs">{{ getItemType(item.itemable_type) }}</td>
                        <td class="py-2 text-sm">{{ item.description }}</td>
                        <td class="py-2 text-sm text-center font-mono">{{ item.quantity }}</td>
                        <td class="py-2 text-sm text-right font-mono">{{ formatCurrency(item.unit_price) }}</td>
                        <td class="py-2 text-sm text-right font-mono font-bold">{{ formatCurrency(item.line_total) }}</td>
                    </tr>
                </tbody>
            </table>

            <!-- Totales -->
            <div class="flex justify-end mb-6">
                <div class="w-64">
                    <div class="flex justify-between py-1 text-sm">
                        <span class="text-gray-500">Subtotal</span>
                        <span class="font-mono">{{ formatCurrency(serviceOrder.subtotal) }}</span>
                    </div>
                    <div v-if="serviceOrder.total_discount > 0" class="flex justify-between py-1 text-sm">
                        <span class="text-gray-500">Descuento</span>
                        <span class="font-mono text-red-500">- {{ formatCurrency(serviceOrder.total_discount) }}</span>
                    </div>
                    <div class="flex justify-between py-1 text-sm">
                        <span class="text-gray-500">Impuestos</span>
                        <span class="font-mono">{{ formatCurrency(serviceOrder.total_tax) }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-t-2 border-gray-800 font-bold text-lg">
                        <span>Total</span>
                        <span class="font-mono">{{ formatCurrency(serviceOrder.final_total) }}</span>
                    </div>
                </div>
            </div>

            <!-- Diagnóstico (si existe) -->
            <div v-if="serviceOrder.technician_diagnosis" class="border-t pt-4 mb-4">
                <h2 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 mb-2">Diagnóstico del técnico</h2>
                <p class="text-sm text-gray-700 m-0 leading-relaxed">{{ serviceOrder.technician_diagnosis }}</p>
            </div>

            <!-- Campos Personalizados (solo en plantilla personalizada) -->
            <div v-if="printTemplate && customFieldDefinitions && customFieldDefinitions.length > 0" class="border-t pt-4">
                <h2 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 mb-3">Detalles adicionales</h2>
                <div class="grid grid-cols-2 gap-3">
                    <div v-for="def in customFieldDefinitions" :key="def.id"
                        v-if="serviceOrder.custom_fields && serviceOrder.custom_fields[def.key]"
                        class="text-sm">
                        <span class="text-gray-500 text-xs">{{ def.name }}:</span>
                        <span v-if="def.type === 'pattern'" class="inline-block ml-1">
                            <PatternLock :modelValue="serviceOrder.custom_fields[def.key]" read-only class="transform scale-50 origin-top-left" />
                        </span>
                        <span v-else class="font-medium ml-1">{{ getFormattedCustomValue(def, serviceOrder.custom_fields[def.key]) }}</span>
                    </div>
                </div>
            </div>
        </div>
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
