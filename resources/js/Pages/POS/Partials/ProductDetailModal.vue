<script setup>
import { ref, computed, watch } from 'vue';

const props = defineProps({
    product: Object,
    visible: Boolean,
});

const emit = defineEmits(['update:visible', 'addToCart']);

// --- Estado local ---
const selectedVariants = ref({});
const currentPrice = ref(0);
const currentStock = ref(0);
const currentSku = ref('');
// CAMBIO: Nuevo estado para la imagen mostrada
const currentImage = ref('');

// CAMBIO: El watch ahora también maneja el precio original de la promoción
watch(() => props.product, (newProduct) => {
    selectedVariants.value = {};
    if (newProduct) {
        currentPrice.value = newProduct.price;
        currentStock.value = newProduct.stock;
        currentSku.value = newProduct.sku;
        currentImage.value = newProduct.image;
        // Se guarda el precio original para mostrarlo
        originalPrice.value = newProduct.promotion ? newProduct.promotion.original_price : newProduct.price;
    }
}, { deep: true });

const selectOption = (variantName, value) => {
    if (selectedVariants.value[variantName] === value) {
        delete selectedVariants.value[variantName];
    } else {
        selectedVariants.value[variantName] = value;
    }
};

const isOptionSelected = (variantName, value) => {
    return selectedVariants.value[variantName] === value;
};

const allVariantTypes = computed(() => props.product ? Object.keys(props.product.variants) : []);

const selectedCombination = computed(() => {
    if (!props.product || allVariantTypes.value.length === 0) return null;
    if (Object.keys(selectedVariants.value).length !== allVariantTypes.value.length) {
        return null;
    }
    return props.product.variant_combinations.find(combo => {
        return allVariantTypes.value.every(variantName => {
            return combo.attributes[variantName] === selectedVariants.value[variantName];
        });
    });
});

const isSelectionComplete = computed(() => {
    if (!props.product || !props.product.variants) return false;
    const totalVariantTypes = Object.keys(props.product.variants).length;
    return totalVariantTypes > 0 && Object.keys(selectedVariants.value).length === totalVariantTypes;
});

const isCombinationInvalid = computed(() => {
    return isSelectionComplete.value && !selectedCombination.value;
});

// Actualiza todos los datos dinámicos cuando cambia la selección
watch(selectedCombination, (combo) => {
    if (props.product) {
        const basePrice = props.product.promotion ? props.product.promotion.original_price : props.product.price;
        if (combo) {
            const promoPrice = props.product.price + combo.price_modifier;
            currentPrice.value = promoPrice;
            originalPrice.value = basePrice + combo.price_modifier;
            currentPrice.value = props.product.price + combo.price_modifier;
            currentStock.value = combo.stock;
            currentSku.value = `${props.product.sku}-${combo.sku_suffix}`;
            // CAMBIO: Lógica para actualizar la imagen
            currentImage.value = combo.image_url || props.product.image;
        } else {
            currentPrice.value = props.product.price;
            currentStock.value = props.product.stock;
            currentSku.value = props.product.sku;
            currentImage.value = props.product.image;
            originalPrice.value = props.product.promotion ? props.product.promotion.original_price : props.product.price;
        }
    }
});

const canAddToCart = computed(() => {
    if (!props.product) return false;
    if (!props.product.variant_combinations || props.product.variant_combinations.length === 0) {
        return props.product.stock > 0;
    }
    return selectedCombination.value && selectedCombination.value.stock > 0;
});

const closeModal = () => emit('update:visible', false);

const addProductToCart = () => {
    if (!canAddToCart.value) return;
    if (!selectedCombination.value && (!props.product.variants || Object.keys(props.product.variants).length === 0)) {
        emit('addToCart', { product: props.product });
    } else if (selectedCombination.value) {
        emit('addToCart', {
            product: props.product,
            variant: selectedCombination.value,
        });
    }
    closeModal();
};

// --- CAMBIO: Se agrega estado para el precio original ---
const originalPrice = ref(props.product?.promotion ? props.product.promotion.original_price : props.product?.price || 0);
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Detalles del Producto"
        :style="{ width: '450px' }">
        <div v-if="product" class="text-gray-800 dark:text-gray-200">
            <div class="relative mb-4">
                <!-- CAMBIO: La imagen ahora es dinámica -->
                <img :src="currentImage" :alt="product.name" class="w-full h-64 object-cover rounded-md">
            </div>
            <div>
                <h2 class="text-2xl font-bold mb-1">{{ product.name }}</h2>
                <p class="text-md text-gray-500 dark:text-gray-400 mb-4">{{ product.category }}</p>

                <!-- Selector de Variantes -->
                <div v-if="product.variants && Object.keys(product.variants).length > 0" class="space-y-4 mb-4">
                    <div v-for="(options, variantName) in product.variants" :key="variantName">
                        <p class="text-sm font-semibold text-gray-600 dark:text-gray-300 mb-2 capitalize">{{ variantName
                        }}</p>
                        <div class="flex flex-wrap gap-2">
                            <Button v-for="option in options" :key="option.value" :label="option.value"
                                :outlined="!isOptionSelected(variantName, option.value)" severity="secondary"
                                size="small" @click="selectOption(variantName, option.value)"
                                v-tooltip.bottom="`Stock disponible: ${option.stock}`" />
                        </div>
                    </div>
                    <Message v-if="isCombinationInvalid" severity="warn" :closable="false">
                        Esta combinación de variantes no existe.
                    </Message>
                </div>

                <!-- Info Dinámica -->
                <div class="bg-gray-100 dark:bg-gray-800 p-4 rounded-lg">
                    <!-- CAMBIO: Muestra precio de oferta y original -->
                    <div v-if="product.promotion || currentPrice < originalPrice"
                        class="flex justify-between items-baseline">
                        <span class="text-gray-500">Oferta:</span>
                        <div class="flex items-baseline gap-2">
                            <p class="text-3xl font-bold text-red-600">${{ currentPrice.toFixed(2) }}</p>
                            <del class="text-xl text-gray-500">${{ originalPrice.toFixed(2) }}</del>
                        </div>
                    </div>
                    <div v-else
                        class="flex justify-between items-center text-3xl font-bold text-gray-900 dark:text-gray-100">
                        <span>Precio:</span>
                        <span>${{ currentPrice.toFixed(2) }}</span>
                    </div>
                    <div class="flex justify-between items-center text-sm mt-2">
                        <span class="text-gray-500 dark:text-gray-400">SKU:</span>
                        <span class="font-medium font-mono">{{ currentSku }}</span>
                    </div>
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-gray-500 dark:text-gray-400">Stock Disponible:</span>
                        <span class="font-bold" :class="currentStock > 0 ? 'text-green-600' : 'text-red-600'">{{
                            currentStock }}</span>
                    </div>
                </div>
                <Message v-if="selectedCombination && selectedCombination.stock <= 0" severity="error" :closable="false"
                    class="mt-4">
                    Esta variante no tiene stock disponible.
                </Message>

                <div v-if="product.description" class="pt-4 mt-4 border-t border-gray-200 dark:border-gray-700">
                    <p class="text-gray-500 dark:text-gray-400 mb-1">Descripción:</p>
                    <div class="text-gray-600 dark:text-gray-300 list-disc list-inside prose-sm dark:prose-invert"
                        v-html="product.description"></div>
                </div>
            </div>
        </div>
        <template #footer>
            <div v-if="product">
                <Button label="Cancelar" text severity="secondary" @click="closeModal" />
                <Button label="Agregar al carrito" icon="pi pi-shopping-cart" @click="addProductToCart"
                    :disabled="!canAddToCart" />
            </div>
        </template>
    </Dialog>
</template>