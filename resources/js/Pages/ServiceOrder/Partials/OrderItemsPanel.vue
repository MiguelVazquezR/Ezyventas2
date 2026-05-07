<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Tag from 'primevue/tag';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';

const props = defineProps({
    serviceOrder: Object,
    isCancelled: Boolean,
});

const formatCurrency = (value) => {
    if (value === null || value === undefined) return 'N/A';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

const getItemType = (itemableType) => {
    if (itemableType === 'App\\Models\\Product' || itemableType === 'App\\Models\\ProductAttribute') {
        return { text: 'Refacción', severity: 'info', icon: 'pi pi-cog' };
    }
    if (itemableType === 'App\\Models\\Service' || itemableType === 'App\\Models\\ServiceVariant') {
        return { text: 'Servicio', severity: 'success', icon: 'pi pi-wrench' };
    }
    return { text: 'Otro', severity: 'secondary', icon: 'pi pi-box' };
};

const getItemUrl = (item) => {
    // Si no está registrado en el catálogo o no cargó la relación, devolvemos null
    if (!item.itemable_id || !item.itemable) return null;

    if (item.itemable_type === 'App\\Models\\Product') {
        return route('products.show', item.itemable_id);
    }
    if (item.itemable_type === 'App\\Models\\ProductAttribute') {
        return route('products.show', item.itemable.product_id); // Redirige al producto padre
    }
    if (item.itemable_type === 'App\\Models\\Service') {
        return route('services.show', item.itemable_id);
    }
    if (item.itemable_type === 'App\\Models\\ServiceVariant') {
        return route('services.show', item.itemable.service_id); // Redirige al servicio padre
    }
    
    return null;
};

// --- TESLA UI PASS-THROUGH (PT) ---
const dataTablePt = {
    root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
    headerRow: { class: 'bg-gray-50 dark:bg-[#1a1a1a]' },
    headerCell: { class: 'bg-transparent text-[10px] uppercase tracking-widest text-gray-500 font-bold py-4 px-4 border-b border-gray-100 dark:border-[#3a3a3a]' },
    bodyRow: { class: 'dark:bg-[#232323] hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors text-sm text-gray-700 dark:text-gray-300 group' },
    bodyCell: { class: 'py-4 px-4 border-b border-gray-50 dark:border-[#2a2a2a]' },
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[9px] !uppercase !tracking-widest !font-bold' },
    icon: { class: '!text-[9px] !mr-1.5' }
};
</script>

<template>
    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
        
        <!-- Header -->
        <div class="mb-6 flex justify-between items-start gap-4">
            <div>
                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Refacciones y mano de obra</h2>
                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Desglose de conceptos de la orden</p>
            </div>
            <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                <i class="pi pi-box !text-sm text-blue-500"></i>
            </div>
        </div>

        <div class="flex-grow flex flex-col">
            <DataTable :value="serviceOrder.items" responsiveLayout="scroll" :pt="dataTablePt">
                
                <Column header="Tipo" style="width: 8rem">
                    <template #body="{ data }">
                        <Tag 
                            :value="getItemType(data.itemable_type).text" 
                            :severity="getItemType(data.itemable_type).severity" 
                            :icon="getItemType(data.itemable_type).icon"
                            :pt="tagPt" 
                        />
                    </template>
                </Column>
                
                <Column field="description" header="Descripción">
                    <template #body="{ data }">
                        <!-- Si tiene URL (está en el catálogo) -->
                        <Link v-if="getItemUrl(data)" :href="getItemUrl(data)" class="text-sm font-medium text-primary-600 dark:text-primary-400 hover:text-primary-500 hover:underline inline-flex items-center gap-2 m-0 transition-colors">
                            {{ data.description }}
                            <i class="pi pi-external-link !text-[10px] opacity-70"></i>
                        </Link>
                        
                        <!-- Si no está en el catálogo (escrito manualmente o eliminado) -->
                        <span v-else class="text-sm font-medium text-gray-900 dark:text-gray-100 m-0 leading-tight">
                            {{ data.description }}
                        </span>

                        <!-- Añadimos la nota si es un Producto (Refacción) con ID -->
                        <div v-if="['App\\Models\\Product', 'App\\Models\\ProductAttribute'].includes(data.itemable_type) && data.itemable_id"
                            class="text-[10px] text-gray-500 dark:text-gray-400 italic mt-1.5 m-0 block">
                            (Se {{ isCancelled ? 'devolvió' : 'descontó' }} {{ Math.round(data.quantity) }} unidad(es) {{ isCancelled ? 'al' : 'del' }} stock)
                        </div>
                    </template>
                </Column>
                
                <Column field="quantity" header="Cant." class="text-center" headerClass="text-center" style="width: 6rem">
                    <template #body="{ data }">
                        <span class="font-mono text-sm text-gray-900 dark:text-white">{{ Math.round(data.quantity) }}</span>
                    </template>
                </Column>
                
                <Column field="unit_price" header="Precio Unit." class="text-right" headerClass="text-right" style="width: 10rem">
                    <template #body="{ data }">
                        <span class="font-mono text-sm text-gray-900 dark:text-gray-200">{{ formatCurrency(data.unit_price) }}</span>
                    </template>
                </Column>
                
                <Column field="line_total" header="Total" class="text-right" headerClass="text-right" style="width: 10rem">
                    <template #body="{ data }">
                        <span class="font-mono text-base font-bold text-gray-900 dark:text-white m-0">{{ formatCurrency(data.line_total) }}</span>
                    </template>
                </Column>
                
                <template #empty>
                    <div class="flex flex-col items-center justify-center text-center py-8 opacity-60">
                        <i class="pi pi-inbox !text-3xl text-gray-400 mb-3"></i>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin conceptos</p>
                        <p class="text-xs text-gray-400 mt-1">No se han agregado refacciones o mano de obra a la orden.</p>
                    </div>
                </template>
            </DataTable>
        </div>
        
        <!-- Recuadro de Totales (Estilo Ticket Mate) -->
        <div class="flex justify-end mt-6">
            <div class="w-full sm:w-80 bg-gray-50 dark:bg-[#1a1a1a] p-4 lg:p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3">
                <div class="flex justify-between items-center">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Subtotal</span>
                    <span class="font-mono text-sm text-gray-900 dark:text-gray-300 m-0">{{ formatCurrency(serviceOrder.subtotal) }}</span>
                </div>
                
                <div v-if="serviceOrder.discount_amount > 0" class="flex justify-between items-center">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-1">
                        <i class="pi pi-tag !text-[9px]"></i> Descuento ({{ serviceOrder.discount_type === 'percentage' ? `${serviceOrder.discount_value}%` : 'Fijo' }})
                    </span>
                    <span class="font-mono text-sm text-red-500 m-0">- {{ formatCurrency(serviceOrder.discount_amount) }}</span>
                </div>
                
                <div class="border-t border-gray-200 dark:border-[#2a2a2a] my-1"></div>
                
                <div class="flex justify-between items-center">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-900 dark:text-gray-100 m-0">Total neto</span>
                    <span class="font-light tracking-tight text-xl text-gray-900 dark:text-white m-0">{{ formatCurrency(serviceOrder.final_total) }}</span>
                </div>
            </div>
        </div>
    </div>
</template>