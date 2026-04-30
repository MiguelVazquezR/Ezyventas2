<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

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
        return { text: 'Refacción', severity: 'info' };
    }
    if (itemableType === 'App\\Models\\Service' || itemableType === 'App\\Models\\ServiceVariant') {
        return { text: 'Servicio', severity: 'success' };
    }
    return { text: 'Otro', severity: 'secondary' };
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
</script>

<template>
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
        <h2 class="text-lg font-semibold border-b pb-3 mb-4">Refacciones y mano de obra</h2>
        <DataTable :value="serviceOrder.items" class="p-datatable-sm">
            <Column header="Tipo" style="width: 8rem">
                <template #body="{ data }">
                    <Tag :value="getItemType(data.itemable_type).text" :severity="getItemType(data.itemable_type).severity" />
                </template>
            </Column>
            <Column field="description" header="Descripción">
                <template #body="{ data }">
                    <!-- Si tiene URL (está en el catálogo) -->
                    <Link v-if="getItemUrl(data)" :href="getItemUrl(data)" class="text-blue-600 dark:text-blue-400 hover:underline inline-flex items-center gap-2">
                        {{ data.description }}
                        <i class="pi pi-external-link text-xs opacity-70"></i>
                    </Link>
                    
                    <!-- Si no está en el catálogo (escrito manualmente o eliminado) -->
                    <span v-else class="text-gray-800 dark:text-gray-200">
                        {{ data.description }}
                    </span>

                    <!-- Añadimos la nota si es un Producto (Refacción) con ID -->
                    <div v-if="['App\\Models\\Product', 'App\\Models\\ProductAttribute'].includes(data.itemable_type) && data.itemable_id"
                        class="text-xs text-gray-500 dark:text-gray-400 italic mt-1">
                        (Se {{ isCancelled ? 'devolvió' : 'descontó' }} {{ data.quantity }} unidad(es) {{ isCancelled ? 'al' : 'del' }} stock)
                    </div>
                </template>
            </Column>
            <Column field="quantity" header="Cantidad" style="width: 6rem" class="text-center"></Column>
            <Column field="unit_price" header="Precio Unit." style="width: 10rem" class="text-right">
                <template #body="{ data }">{{ formatCurrency(data.unit_price) }}</template>
            </Column>
            <Column field="line_total" header="Total" style="width: 10rem" class="text-right font-semibold">
                <template #body="{ data }">{{ formatCurrency(data.line_total) }}</template>
            </Column>
            <template #empty>
                <div class="text-center text-gray-500 py-4">
                    No se han agregado refacciones o mano de obra.
                </div>
            </template>
        </DataTable>
        
        <div class="flex justify-end mt-4">
            <div class="w-full max-w-xs text-right space-y-2 text-sm">
                <div class="flex justify-between">
                    <span>Subtotal:</span>
                    <span class="font-semibold">{{ formatCurrency(serviceOrder.subtotal) }}</span>
                </div>
                <div v-if="serviceOrder.discount_amount > 0" class="flex justify-between text-red-600">
                    <span>Descuento ({{ serviceOrder.discount_type === 'percentage' ? `${serviceOrder.discount_value}%` : 'Fijo' }}):</span>
                    <span class="font-semibold">(-) {{ formatCurrency(serviceOrder.discount_amount) }}</span>
                </div>
                <div class="flex justify-between font-bold text-lg border-t pt-2 mt-2">
                    <span>Total:</span>
                    <span>{{ formatCurrency(serviceOrder.final_total) }}</span>
                </div>
            </div>
        </div>
    </div>
</template>