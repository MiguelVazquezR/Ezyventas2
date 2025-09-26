<script setup>
import { ref, computed, watch } from 'vue';
import InputLabel from './InputLabel.vue';

const props = defineProps({
    visible: Boolean,
    product: Object,
});

const emit = defineEmits(['update:visible', 'variant-selected']);

const selectedOptions = ref({});
const availableAttributes = ref([]);

watch(() => props.product, (newProduct) => {
    if (newProduct) {
        const attributes = {};
        newProduct.product_attributes.forEach(pa => {
            Object.entries(pa.attributes).forEach(([key, value]) => {
                if (!attributes[key]) {
                    attributes[key] = new Set();
                }
                attributes[key].add(value);
            });
        });

        availableAttributes.value = Object.entries(attributes).map(([name, values]) => ({
            name,
            options: Array.from(values)
        }));
        
        selectedOptions.value = {};
    }
});

const allOptionsSelected = computed(() => {
    return props.product && Object.keys(selectedOptions.value).length === availableAttributes.value.length;
});

const selectedVariant = computed(() => {
    if (!allOptionsSelected.value) {
        return null;
    }
    return props.product.product_attributes.find(pa => {
        return Object.entries(selectedOptions.value).every(
            ([key, value]) => pa.attributes[key] === value
        );
    });
});

// SOLUCIÓN: Computed property para mostrar el mensaje de error
const showUnavailableMessage = computed(() => {
    return allOptionsSelected.value && !selectedVariant.value;
});

const closeModal = () => {
    emit('update:visible', false);
};

const confirmSelection = () => {
    if (selectedVariant.value) {
        emit('variant-selected', selectedVariant.value);
        closeModal();
    }
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Seleccionar Variante" :style="{ width: '30rem' }">
        <div v-if="product" class="p-2 space-y-4">
            <div v-for="attr in availableAttributes" :key="attr.name">
                <InputLabel :value="attr.name" />
                <Select v-model="selectedOptions[attr.name]" :options="attr.options" class="w-full mt-1" :placeholder="`Selecciona ${attr.name}`" />
            </div>

            <!-- Información de la variante si existe -->
            <div v-if="selectedVariant" class="mt-4 p-3 bg-gray-50 dark:bg-gray-700 rounded-lg transition-all duration-300">
                <p class="font-semibold">Precio de la variante: {{ new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(parseFloat(product.selling_price) + parseFloat(selectedVariant.selling_price_modifier)) }}</p>
                <p class="text-sm text-gray-500">Stock disponible: {{ selectedVariant.current_stock }}</p>
            </div>

            <!-- Mensaje de retroalimentación si la variante no existe -->
            <div v-if="showUnavailableMessage" class="mt-4 p-3 bg-yellow-50 dark:bg-yellow-900/20 text-yellow-800 dark:text-yellow-200 rounded-lg text-sm flex items-start gap-3 transition-all duration-300">
                <i class="pi pi-exclamation-triangle mt-1"></i>
                <div>
                    <p class="font-semibold">Combinación no disponible</p>
                    <p class="mt-1">Para usar esta variante, primero edita el producto y actívala en la sección de "Gestión de variantes".</p>
                </div>
            </div>
        </div>
        <div class="flex justify-end gap-2 mt-6">
            <Button type="button" label="Cancelar" severity="secondary" @click="closeModal"></Button>
            <Button type="button" label="Confirmar" @click="confirmSelection" :disabled="!selectedVariant"></Button>
        </div>
    </Dialog>
</template>