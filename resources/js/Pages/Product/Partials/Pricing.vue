<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { useConfirm } from 'primevue/useconfirm';

const props = defineProps({
    form: Object,
});

const confirm = useConfirm();

// --- LÓGICA DE PRECIOS DE MAYOREO ---
const addPriceTier = () => {
    if (!props.form.price_tiers) {
        props.form.price_tiers = [];
    }
    props.form.price_tiers.push({ min_quantity: 2, price: null });
};

const confirmRemovePriceTier = (event, index) => {
    confirm.require({
        target: event.currentTarget,
        message: '¿Estás seguro de que quieres eliminar este nivel de precio?',
        group: 'price-tiers-delete',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'No',
        accept: () => {
            props.form.price_tiers.splice(index, 1);
        }
    });
};
</script>

<template>
    <div id="pricing" class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md scroll-mt-24">
        <h2 class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4 text-gray-800 dark:text-gray-200">
            Precios
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Precios Base -->
            <div>
                <InputLabel for="cost_price" value="Precio de costo (Opcional)" />
                <InputNumber v-model="form.cost_price" id="cost_price" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" placeholder="$0.00" />
                <InputError :message="form.errors.cost_price" class="mt-2" />
            </div>

            <div>
                <InputLabel for="selling_price" value="Precio de venta *" />
                <InputNumber v-model="form.selling_price" id="selling_price" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" placeholder="$0.00" />
                <InputError :message="form.errors.selling_price" class="mt-2" />
            </div>

            <!-- Precios de Mayoreo (Price Tiers) -->
            <div class="col-span-full mt-4 pt-4 border-t border-gray-100 dark:border-gray-700">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-4 gap-2">
                    <div>
                        <InputLabel value="Precios de mayoreo (Opcional)" class="!font-bold text-gray-800 dark:text-gray-200" />
                        <p class="text-sm text-gray-500 mt-1">
                            Ejemplo: Si compran 5 o más, el precio baja a $90. Si compran 10 o más, baja a $80.
                        </p>
                    </div>
                    <Button @click="addPriceTier" label="Añadir nivel" icon="pi pi-plus" size="small" outlined />
                </div>
                
                <div v-if="!form.price_tiers || form.price_tiers.length === 0" class="text-sm text-gray-500 italic bg-gray-50 dark:bg-gray-700/50 p-4 rounded-lg text-center border border-dashed border-gray-300 dark:border-gray-600">
                    No has configurado precios especiales por volumen para este producto.
                </div>
                
                <div v-else class="space-y-3">
                    <div v-for="(tier, index) in form.price_tiers" :key="index" class="flex flex-col sm:flex-row items-start sm:items-center gap-4 bg-gray-50 dark:bg-gray-700/50 p-3 rounded-lg border border-gray-200 dark:border-gray-600">
                        <div class="flex-1 w-full">
                            <InputLabel :value="`A partir de (cantidad)`" class="text-xs mb-1" />
                            <InputNumber v-model="tier.min_quantity" :min="2" class="w-full" showButtons />
                            <InputError :message="form.errors[`price_tiers.${index}.min_quantity`]" class="mt-1" />
                        </div>
                        <div class="flex-1 w-full">
                            <InputLabel :value="`Precio unitario`" class="text-xs mb-1" />
                            <InputNumber v-model="tier.price" mode="currency" currency="MXN" locale="es-MX" class="w-full" />
                            <InputError :message="form.errors[`price_tiers.${index}.price`]" class="mt-1" />
                        </div>
                        <div class="pt-5 flex justify-end w-full sm:w-auto">
                            <Button icon="pi pi-trash" severity="danger" text rounded @click="confirmRemovePriceTier($event, index)" v-tooltip.top="'Eliminar nivel'" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>