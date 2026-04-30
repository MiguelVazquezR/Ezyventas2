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
</script>

<template>
    <Card>
        <template #title>Detalles de los conceptos</template>
        <template #content>
            <DataTable :value="transaction.items" class="p-datatable-sm">
                <Column header="SKU">
                    <template #body="{ data }">
                        <span class="text-gray-500 dark:text-gray-400 font-mono text-xs">{{ getItemSku(data) }}</span>
                    </template>
                </Column>
                <Column field="description" header="Descripción">
                    <template #body="{ data }">
                        <!-- Si tiene URL (está en el catálogo) -->
                        <Link v-if="getItemUrl(data)" :href="getItemUrl(data)" class="text-blue-600 dark:text-blue-400 hover:underline inline-flex items-center gap-2">
                            {{ data.description }}
                            <i class="pi pi-external-link text-xs opacity-70"></i>
                        </Link>
                        
                        <!-- Si no está en el catálogo -->
                        <span v-else class="text-gray-800 dark:text-gray-200">
                            {{ data.description }}
                        </span>
                    </template>
                </Column>
                <Column field="quantity" header="Cantidad" class="text-center"></Column>
                <Column header="Precio unitario">
                    <template #body="{ data }">
                        <div>
                            <del v-if="parseFloat(data.discount_amount || 0) !== 0" class="text-gray-500 text-xs">
                                {{ formatCurrency(parseFloat(data.unit_price || 0) + parseFloat(data.discount_amount || 0)) }}
                            </del>
                            <p class="font-semibold m-0">{{ formatCurrency(data.unit_price) }}</p>
                            <p v-if="parseFloat(data.discount_amount) > 0" class="text-xs text-green-600 m-0">Ahorro: {{ formatCurrency(data.discount_amount) }}</p>
                        </div>
                    </template>
                </Column>
                <Column field="line_total" header="Total" class="text-right">
                    <template #body="{ data }">{{ formatCurrency(data.line_total) }}</template>
                </Column>
                <template #empty>
                    <div class="text-center py-4">No hay conceptos registrados en esta venta.</div>
                </template>
            </DataTable>
        </template>
    </Card>
</template>