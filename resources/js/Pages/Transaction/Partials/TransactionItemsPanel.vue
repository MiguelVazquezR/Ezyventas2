<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    transaction: Object,
});

const formatCurrency = (val) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(val) || 0);

const getItemSku = (item) => {
    if (!item.itemable) return '';
    if (item.itemable.sku) return item.itemable.sku;
    if (item.itemable.sku_suffix && item.itemable.product) {
        return `${item.itemable.product.sku}-${item.itemable.sku_suffix}`;
    }
    if (item.itemable.sku_suffix) return `...-${item.itemable.sku_suffix}`; 
    return '';
};

const getItemUrl = (item) => {
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
</script>

<template>
    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col h-full">
        
        <!-- Header -->
        <div class="mb-6 flex justify-between items-start gap-4">
            <div>
                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Detalles de los conceptos</h2>
                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Artículos y servicios registrados</p>
            </div>
            <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                <i class="pi pi-box !text-sm text-blue-500"></i>
            </div>
        </div>

        <!-- Content -->
        <div class="flex-grow flex flex-col">
            <DataTable :value="transaction.items" responsiveLayout="scroll" :pt="dataTablePt">
                
                <Column header="SKU">
                    <template #body="{ data }">
                        <span class="text-gray-500 dark:text-gray-400 font-mono text-[10px] uppercase tracking-widest">{{ getItemSku(data) || '--' }}</span>
                    </template>
                </Column>
                
                <Column field="description" header="Descripción">
                    <template #body="{ data }">
                        <!-- Si tiene URL (está en el catálogo) -->
                        <Link v-if="getItemUrl(data)" :href="getItemUrl(data)" class="text-sm font-medium text-primary-600 dark:text-primary-400 hover:text-primary-500 hover:underline inline-flex items-center gap-2 m-0 transition-colors">
                            {{ data.description }}
                            <i class="pi pi-external-link !text-[10px] opacity-70"></i>
                        </Link>
                        
                        <!-- Si no está en el catálogo -->
                        <span v-else class="text-sm font-medium text-gray-900 dark:text-gray-100 m-0 leading-tight">
                            {{ data.description }}
                        </span>
                    </template>
                </Column>
                
                <Column field="quantity" header="Cantidad" class="text-center">
                    <template #body="{ data }">
                        <span class="font-mono text-sm text-gray-900 dark:text-white">{{ Math.round(data.quantity) }}</span>
                    </template>
                </Column>
                
                <Column header="Precio unitario">
                    <template #body="{ data }">
                        <div class="flex flex-col gap-0.5">
                            <del v-if="parseFloat(data.discount_amount || 0) !== 0" class="text-gray-400 dark:text-gray-500 text-[10px] font-mono">
                                {{ formatCurrency(parseFloat(data.unit_price || 0) + parseFloat(data.discount_amount || 0)) }}
                            </del>
                            <span class="font-mono text-sm font-medium text-gray-900 dark:text-gray-200 m-0">{{ formatCurrency(data.unit_price) }}</span>
                            <span v-if="parseFloat(data.discount_amount) > 0" class="text-[10px] text-green-600 dark:text-green-500 font-medium m-0 flex items-center gap-1 mt-0.5">
                                <i class="pi pi-tag !text-[8px]"></i> Ahorro: {{ formatCurrency(data.discount_amount) }}
                            </span>
                        </div>
                    </template>
                </Column>
                
                <Column field="line_total" header="Total" class="text-right">
                    <template #body="{ data }">
                        <span class="font-mono text-base font-bold text-gray-900 dark:text-white m-0">{{ formatCurrency(data.line_total) }}</span>
                    </template>
                </Column>

                <template #empty>
                    <div class="flex flex-col items-center justify-center text-center py-10 opacity-60">
                        <i class="pi pi-inbox !text-3xl text-gray-400 mb-3"></i>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin conceptos</p>
                        <p class="text-xs text-gray-400 mt-1">No hay artículos registrados en esta venta.</p>
                    </div>
                </template>
            </DataTable>
        </div>
    </div>
</template>