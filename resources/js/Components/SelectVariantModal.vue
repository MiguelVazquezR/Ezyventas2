<script setup>
import { ref, computed, watch } from 'vue';
import InputLabel from './InputLabel.vue';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';

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
        return Object.entries(selectedOptions.value).every(([key, value]) => pa.attributes[key] === value);
    });
});

const selectOption = (attributeName, option) => {
    selectedOptions.value[attributeName] = option;
};

const handleConfirmProduct = () => {
    if (selectedProductVariant.value) {
        emit('variant-selected', selectedProductVariant.value);
        emit('update:visible', false);
    }
};


// --- LÓGICA PARA SERVICIOS (OPTIMIZADA) ---
const variantSearch = ref('');
const visibleVariantsCount = ref(50); // Límite inicial de renderizado para evitar Lag

// Resetear búsqueda y límite al abrir el modal o cambiar el ítem
watch(() => props.visible, (newVal) => {
    if (newVal && props.type === 'service') {
        variantSearch.value = '';
        visibleVariantsCount.value = 50;
    }
});

// Reiniciar límite al escribir algo en el buscador
watch(variantSearch, () => {
    visibleVariantsCount.value = 50;
});

// Filtra basándose en el input del buscador
const filteredServiceVariants = computed(() => {
    if (props.type !== 'service' || !props.item || !props.item.variants) return [];
    
    if (!variantSearch.value.trim()) return props.item.variants;
    
    const term = variantSearch.value.toLowerCase().trim();
    return props.item.variants.filter(v => v.name.toLowerCase().includes(term));
});

// Segmenta la lista para no renderizar miles de botones al mismo tiempo
const displayedServiceVariants = computed(() => {
    return filteredServiceVariants.value.slice(0, visibleVariantsCount.value);
});

const loadMoreVariants = () => {
    visibleVariantsCount.value += 50;
};

const handleServiceVariantClick = (variant) => {
    emit('variant-selected', variant);
    emit('update:visible', false);
};

const closeModal = () => {
    emit('update:visible', false);
};
</script>

<template>
    <Dialog 
        :visible="visible" 
        @update:visible="$emit('update:visible', $event)" 
        modal 
        :header="type === 'product' ? 'Seleccionar variante de producto' : 'Seleccionar opción de servicio'" 
        :style="{ width: '35rem' }"
    >
        <div class="p-2">
            
            <!-- VISTA PARA PRODUCTOS -->
            <div v-if="type === 'product' && item" class="space-y-6">
                <p class="text-gray-600 dark:text-gray-300">
                    El producto <strong>{{ item.name }}</strong> tiene múltiples variantes. Por favor, selecciona las características deseadas:
                </p>

                <div v-for="attribute in availableAttributes" :key="attribute.name" class="space-y-2">
                    <InputLabel :value="attribute.name" class="capitalize text-lg" />
                    <div class="flex flex-wrap gap-2">
                        <Button 
                            v-for="option in attribute.options" 
                            :key="option"
                            :label="option"
                            :outlined="selectedOptions[attribute.name] !== option"
                            :severity="selectedOptions[attribute.name] === option ? 'primary' : 'secondary'"
                            @click="selectOption(attribute.name, option)"
                            class="capitalize"
                        />
                    </div>
                </div>

                <div class="mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
                    <div v-if="selectedProductVariant" class="mb-4 bg-green-50 dark:bg-green-900/20 p-3 rounded-lg border border-green-200 dark:border-green-800">
                        <p class="font-semibold text-green-800 dark:text-green-200">Variante seleccionada:</p>
                        <p class="text-sm text-green-700 dark:text-green-300">
                            Precio modificador: 
                            <span class="font-bold">
                                {{ selectedProductVariant.selling_price_modifier > 0 ? '+' : '' }}{{ new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(selectedProductVariant.selling_price_modifier) }}
                            </span>
                        </p>
                    </div>
                    <div v-else class="mb-4 p-3 text-sm text-yellow-700 bg-yellow-50 rounded-lg">
                        <i class="pi pi-info-circle mr-2"></i> Por favor selecciona todas las opciones para continuar.
                    </div>
                    
                    <div class="flex justify-end gap-2">
                        <Button label="Cancelar" severity="secondary" text @click="closeModal" />
                        <Button label="Confirmar Variante" @click="handleConfirmProduct" :disabled="!allOptionsSelected" />
                    </div>
                </div>
            </div>

            <!-- VISTA PARA SERVICIOS (OPTIMIZADA) -->
            <div v-if="type === 'service' && item" class="space-y-4 flex flex-col">
                <p class="text-gray-600 dark:text-gray-300">
                    El servicio <strong>{{ item.name }}</strong> tiene múltiples opciones ({{ item.variants.length }}). Por favor selecciona una:
                </p>

                <!-- BUSCADOR INTERNO -->
                <IconField iconPosition="left" class="w-full" v-if="item.variants && item.variants.length > 5">
                    <InputIcon class="pi pi-search"></InputIcon>
                    <InputText v-model="variantSearch" placeholder="Buscar por modelo o característica..." class="w-full" autofocus />
                </IconField>

                <div class="grid grid-cols-1 gap-2 max-h-96 overflow-y-auto custom-scrollbar pr-1">
                    
                    <!-- RENDERIZADO OPTIMIZADO -->
                    <Button 
                        v-for="variant in displayedServiceVariants" 
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

                    <!-- ESTADOS VACÍOS Y CARGAR MÁS -->
                    <div v-if="filteredServiceVariants.length === 0" class="text-center py-6 text-gray-500 italic bg-gray-50 dark:bg-gray-800 rounded">
                        No se encontró ninguna variante con "{{ variantSearch }}".
                    </div>

                    <div v-if="filteredServiceVariants.length > visibleVariantsCount" class="text-center mt-2">
                        <Button label="Cargar más variantes" size="small" text @click="loadMoreVariants" icon="pi pi-refresh" />
                    </div>
                </div>
            </div>
        </div>
    </Dialog>
</template>