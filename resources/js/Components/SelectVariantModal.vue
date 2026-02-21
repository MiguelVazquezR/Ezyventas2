<script setup>
import { ref, computed, watch } from 'vue';
import InputLabel from './InputLabel.vue';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';

const props = defineProps({
    visible: Boolean,
    item: Object, // Puede ser un producto o un servicio
    type: {
        type: String,
        default: 'product' // 'product' | 'service'
    }
});

const emit = defineEmits(['update:visible', 'variant-selected']);

// --- LÓGICA PARA PRODUCTOS ---
const selectedOptions = ref({});
const availableAttributes = ref([]);

watch(() => props.item, (newItem) => {
    if (props.type === 'product' && newItem && newItem.product_attributes) {
        const attributes = {};
        newItem.product_attributes.forEach(pa => {
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
    return props.type === 'product' && props.item && Object.keys(selectedOptions.value).length === availableAttributes.value.length;
});

const selectedProductVariant = computed(() => {
    if (!allOptionsSelected.value) return null;
    return props.item.product_attributes.find(pa => {
        return Object.entries(selectedOptions.value).every(
            ([key, value]) => pa.attributes[key] === value
        );
    });
});

const showUnavailableMessage = computed(() => {
    return allOptionsSelected.value && !selectedProductVariant.value;
});

// --- LÓGICA PARA SERVICIOS ---
const handleServiceVariantClick = (variant) => {
    emit('variant-selected', variant);
    emit('update:visible', false);
};

// --- ACCIONES GENERALES ---
const confirmSelection = () => {
    if (props.type === 'product' && selectedProductVariant.value) {
        emit('variant-selected', selectedProductVariant.value);
        emit('update:visible', false);
    }
};

const closeModal = () => {
    emit('update:visible', false);
    if (props.type === 'product') {
        selectedOptions.value = {};
    }
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal :header="type === 'product' ? 'Seleccionar opciones' : 'Seleccionar variante de servicio'" :style="{ width: '30rem', maxWidth: '95vw' }">
        
        <!-- SELECTOR DE VARIANTES DE PRODUCTO -->
        <div v-if="type === 'product' && item">
            <div v-if="availableAttributes.length > 0" class="flex flex-col gap-4">
                <div v-for="attr in availableAttributes" :key="attr.name">
                    <InputLabel :value="attr.name" class="mb-2" />
                    <div class="flex flex-wrap gap-2">
                        <Button v-for="option in attr.options" :key="option" :label="option" 
                            :severity="selectedOptions[attr.name] === option ? 'primary' : 'secondary'" 
                            :outlined="selectedOptions[attr.name] !== option" 
                            @click="selectedOptions[attr.name] = option" 
                            size="small" />
                    </div>
                </div>
                
                <div v-if="selectedProductVariant" class="mt-4 p-3 bg-gray-50 dark:bg-gray-700 rounded-lg transition-all duration-300">
                    <p class="font-semibold">Precio de la variante: {{ new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(parseFloat(item.selling_price) + parseFloat(selectedProductVariant.selling_price_modifier)) }}</p>
                    <p class="text-sm text-gray-500">Stock disponible: {{ selectedProductVariant.current_stock }}</p>
                </div>

                <div v-if="showUnavailableMessage" class="mt-4 p-3 bg-yellow-50 dark:bg-yellow-900/20 text-yellow-800 dark:text-yellow-200 rounded-lg text-sm flex items-start gap-3 transition-all duration-300">
                    <i class="pi pi-exclamation-triangle mt-1"></i>
                    <div>
                        <p class="font-semibold">Combinación no disponible</p>
                        <p class="mt-1">Para usar esta variante, primero edita el producto y actívala en la sección de "Gestión de variantes".</p>
                    </div>
                </div>
            </div>
            <div v-else class="text-gray-500 text-sm italic">
                Este producto no tiene opciones configuradas.
            </div>

            <div class="flex justify-end gap-2 mt-6">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal"></Button>
                <Button type="button" label="Confirmar" @click="confirmSelection" :disabled="!selectedProductVariant"></Button>
            </div>
        </div>

        <!-- SELECTOR DE VARIANTES DE SERVICIO -->
        <div v-else-if="type === 'service' && item">
            <div class="flex flex-col gap-3">
                <p class="text-gray-600 dark:text-gray-400 text-sm">
                    El servicio <strong>{{ item.name }}</strong> tiene múltiples opciones. Por favor selecciona una:
                </p>
                <div class="grid grid-cols-1 gap-2 max-h-96 overflow-y-auto custom-scrollbar pr-1">
                    <Button 
                        v-for="variant in item.variants" 
                        :key="variant.id"
                        @click="handleServiceVariantClick(variant)"
                        severity="secondary" 
                        outlined
                        class="!justify-between !w-full !p-3 hover:bg-primary-50 dark:hover:bg-primary-900/20 transition-colors !items-center"
                    >
                        <span class="font-bold text-gray-800 dark:text-gray-200 text-left">{{ variant.name }}</span>
                        <div class="flex flex-col text-right ml-4 shrink-0">
                            <span class="text-primary-600 dark:text-primary-400 font-bold text-lg">
                                {{ new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(variant.price) }}
                            </span>
                            <span v-if="variant.duration_estimate" class="text-xs text-gray-500 mt-1 flex justify-end items-center">
                                <i class="pi pi-clock mr-1"></i>{{ variant.duration_estimate }}
                            </span>
                        </div>
                    </Button>
                </div>
            </div>
        </div>
    </Dialog>
</template>