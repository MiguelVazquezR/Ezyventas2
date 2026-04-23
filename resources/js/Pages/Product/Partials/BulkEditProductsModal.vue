<script setup>
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';

const props = defineProps({
    visible: Boolean,
    products: Array,
});

const emit = defineEmits(['update:visible', 'success']);
const toast = useToast();

const form = useForm({
    items: []
});

const buildFlatList = () => {
    const flatList = [];
    
    props.products.forEach(p => {
        const basePrice = parseFloat(p.selling_price) || 0;

        // 1. Agregar el producto base
        flatList.push({
            id: p.id,
            type: 'product',
            name: p.name,
            sku: p.sku || '',
            selling_price: basePrice,
            cost_price: parseFloat(p.cost_price) || 0,
            min_stock: p.min_stock !== null ? Number(p.min_stock) : null,
            max_stock: p.max_stock !== null ? Number(p.max_stock) : null,
            show_in_pos: Boolean(p.show_in_pos),
            hasVariants: p.product_attributes && p.product_attributes.length > 0
        });

        // 2. Agregar sus variantes calculando el PRECIO TOTAL (Base + Modificador)
        if (p.product_attributes && p.product_attributes.length > 0) {
            p.product_attributes.forEach(v => {
                const modifier = parseFloat(v.selling_price_modifier) || 0;
                
                flatList.push({
                    id: v.id,
                    type: 'variant',
                    product_id: p.id,
                    name: Object.values(v.attributes).join(' - '),
                    sku: v.sku_suffix || '',
                    selling_price: basePrice + modifier, // Mostramos el precio final
                    min_stock: v.min_stock !== null ? Number(v.min_stock) : null,
                    max_stock: v.max_stock !== null ? Number(v.max_stock) : null,
                });
            });
        }
    });
    
    form.items = flatList;
};

watch(() => props.visible, (isVisible) => {
    if (isVisible) {
        buildFlatList();
    }
});

const closeModal = () => {
    emit('update:visible', false);
    form.reset();
};

const submitBulkUpdate = () => {
    form.post(route('products.bulkUpdate'), {
        preserveScroll: true,
        onSuccess: () => {
            closeModal();
            emit('success');
        },
        onError: () => {
            toast.add({ severity: 'error', summary: 'Error', detail: 'Revisa los campos en rojo e intenta de nuevo.', life: 4000 });
        }
    });
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Edición Masiva de Productos"
        :style="{ width: '90vw', maxWidth: '1200px' }" :closable="!form.processing">
        
        <div class="mb-4 text-sm text-gray-600 dark:text-gray-400 bg-blue-50 dark:bg-blue-900/20 p-3 rounded-md border border-blue-100 dark:border-blue-800">
            <i class="pi pi-info-circle mr-2 text-blue-500"></i>
            Las variantes se muestran debajo de su producto principal. Los campos de <b>Costo</b> y <b>Mostrar en POS</b> para las variantes están bloqueados porque heredan la configuración del producto principal.
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400 border-collapse">
                <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400 sticky top-0 z-10 shadow-sm">
                    <tr>
                        <th scope="col" class="px-4 py-3 min-w-[200px]">Nombre / Variante</th>
                        <th scope="col" class="px-4 py-3 min-w-[120px]">SKU</th>
                        <th scope="col" class="px-4 py-3 min-w-[120px]">Precio Venta</th>
                        <th scope="col" class="px-4 py-3 min-w-[120px]">Costo</th>
                        <th scope="col" class="px-4 py-3 min-w-[100px]">Stock Mín.</th>
                        <th scope="col" class="px-4 py-3 min-w-[100px]">Stock Máx.</th>
                        <th scope="col" class="px-4 py-3 text-center">En POS</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(item, index) in form.items" :key="`${item.type}-${item.id}`"
                        :class="[
                            'border-b dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50',
                            item.type === 'variant' ? 'bg-gray-50/50 dark:bg-gray-800/30' : 'bg-white dark:bg-gray-800'
                        ]">
                        
                        <!-- Nombre -->
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-2">
                                <i v-if="item.type === 'variant'" class="pi pi-level-down-right text-gray-400 ml-4"></i>
                                <InputText v-if="item.type === 'product'" v-model="item.name" class="w-full !p-1 !text-sm" />
                                <span v-else class="text-gray-600 dark:text-gray-400 font-medium whitespace-nowrap">{{ item.name }}</span>
                            </div>
                        </td>

                        <!-- SKU -->
                        <td class="px-4 py-2">
                            <InputText v-model="item.sku" class="w-full !p-1 !text-sm" placeholder="SKU..." />
                        </td>

                        <!-- Precio -->
                        <td class="px-4 py-2">
                            <InputNumber v-model="item.selling_price" mode="currency" currency="MXN" locale="es-MX" class="w-full" inputClass="!p-1 !text-sm w-full" />
                        </td>

                        <!-- Costo -->
                        <td class="px-4 py-2">
                            <InputNumber v-if="item.type === 'product'" v-model="item.cost_price" mode="currency" currency="MXN" locale="es-MX" class="w-full" inputClass="!p-1 !text-sm w-full" />
                            <InputText v-else disabled placeholder="Heredado" class="w-full opacity-60 italic !p-1 !text-sm text-center" v-tooltip.top="'Heredado del producto'" />
                        </td>

                        <!-- Stock Mínimo -->
                        <td class="px-4 py-2">
                            <InputNumber v-model="item.min_stock" :useGrouping="false" class="w-full" inputClass="!p-1 !text-sm w-full" placeholder="Mín" />
                        </td>

                        <!-- Stock Máximo -->
                        <td class="px-4 py-2">
                            <InputNumber v-model="item.max_stock" :useGrouping="false" class="w-full" inputClass="!p-1 !text-sm w-full" placeholder="Máx" />
                        </td>

                        <!-- Mostrar en POS -->
                        <td class="px-4 py-2 text-center">
                            <InputSwitch v-if="item.type === 'product'" v-model="item.show_in_pos" />
                            <InputSwitch v-else disabled :modelValue="true" class="opacity-40" v-tooltip.top="'Heredado del producto base'" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <template #footer>
            <div class="flex justify-end gap-2 mt-4">
                <Button label="Cancelar" icon="pi pi-times" text @click="closeModal" :disabled="form.processing" />
                <Button label="Guardar cambios masivos" icon="pi pi-check" @click="submitBulkUpdate" :loading="form.processing" />
            </div>
        </template>
    </Dialog>
</template>