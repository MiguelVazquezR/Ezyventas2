<script setup>
import { ref, computed, watch } from 'vue';

const props = defineProps({
    product: Object,
    visible: Boolean,
});

const emit = defineEmits(['update:visible', 'addToCart']);

const selectedVariants = ref({});
const currentPrice = ref(0);
const currentStock = ref(0);
const currentReservedStock = ref(0);
const currentSku = ref('');
const currentImage = ref('');
const originalPrice = ref(0);
const carouselIndex = ref(0);

const isComposite = computed(() => props.product && props.product.components && props.product.components.length > 0);

// Watch for a new product to reset the state
watch(() => props.product, (newProduct) => {
    selectedVariants.value = {};
    carouselIndex.value = 0;
    if (newProduct) {
        currentPrice.value = newProduct.price;
        originalPrice.value = newProduct.original_price;
        currentStock.value = newProduct.stock;
        currentReservedStock.value = newProduct.reserved_stock;
        currentSku.value = newProduct.sku;
        currentImage.value = (newProduct.general_images && newProduct.general_images.length > 0)
            ? newProduct.general_images[0]
            : newProduct.image;
    }
}, { deep: true });

// --- Carousel Logic ---
const nextImage = () => {
    if (props.product.general_images && props.product.general_images.length > 1) {
        carouselIndex.value = (carouselIndex.value + 1) % props.product.general_images.length;
        currentImage.value = props.product.general_images[carouselIndex.value];
    }
};
const prevImage = () => {
    if (props.product.general_images && props.product.general_images.length > 1) {
        carouselIndex.value = (carouselIndex.value - 1 + props.product.general_images.length) % props.product.general_images.length;
        currentImage.value = props.product.general_images[carouselIndex.value];
    }
};

const isOptionDisabled = (variantName, optionValue) => {
    if (!props.product || !props.product.variant_combinations) return false;

    const otherSelectedVariants = { ...selectedVariants.value };
    delete otherSelectedVariants[variantName];

    if (Object.keys(otherSelectedVariants).length === 0) return false;

    const exists = props.product.variant_combinations.some(combo => {
        const matchesOtherSelected = Object.entries(otherSelectedVariants).every(
            ([key, value]) => combo.attributes[key] === value
        );
        return combo.attributes[variantName] === optionValue && matchesOtherSelected;
    });

    return !exists;
};

const selectOption = (variantName, value) => {
    if (isOptionDisabled(variantName, value)) return;
    if (selectedVariants.value[variantName] === value) {
        delete selectedVariants.value[variantName];
    } else {
        selectedVariants.value[variantName] = value;
    }
};

const isOptionSelected = (variantName, value) => selectedVariants.value[variantName] === value;
const allVariantTypes = computed(() => props.product ? Object.keys(props.product.variants) : []);

const selectedCombination = computed(() => {
    if (!props.product || allVariantTypes.value.length === 0) return null;
    if (Object.keys(selectedVariants.value).length !== allVariantTypes.value.length) return null;
    return props.product.variant_combinations.find(combo =>
        allVariantTypes.value.every(name => combo.attributes[name] === selectedVariants.value[name])
    );
});

const isSelectionComplete = computed(() => {
    if (!props.product || !props.product.variants) return false;
    return Object.keys(props.product.variants).length > 0 && Object.keys(selectedVariants.value).length === Object.keys(props.product.variants).length;
});

const isCombinationInvalid = computed(() => isSelectionComplete.value && !selectedCombination.value);

// --- MEJORA: Watcher for intermediate variant selection to update image ---
watch(selectedVariants, (newSelections) => {
    // This logic runs when selections change but are not yet a full combination.
    if (selectedCombination.value) return;

    let foundImageUrl = null;
    if (props.product && props.product.variant_combinations) {
        const selectedKeys = Object.keys(newSelections);
        for (let i = selectedKeys.length - 1; i >= 0; i--) {
            const variantName = selectedKeys[i];
            const optionValue = newSelections[variantName];
            const comboWithImage = props.product.variant_combinations.find(
                combo => combo.attributes[variantName] === optionValue && combo.image_url
            );
            if (comboWithImage) {
                foundImageUrl = comboWithImage.image_url;
                break;
            }
        }
    }

    if (foundImageUrl) {
        currentImage.value = foundImageUrl;
    } else {
        // Fallback to the current carousel image or default image
        currentImage.value = (props.product.general_images && props.product.general_images.length > 0)
            ? props.product.general_images[carouselIndex.value]
            : props.product.image;
    }
}, { deep: true });

// Watcher for the final, complete combination
watch(selectedCombination, (combo) => {
    if (props.product) {
        if (combo) {
            currentPrice.value = props.product.price + combo.price_modifier;
            originalPrice.value = props.product.original_price + combo.price_modifier;
            currentStock.value = combo.stock;
            currentReservedStock.value = combo.reserved_stock;
            currentSku.value = `${props.product.sku ?? ''}-${combo.sku_suffix ?? ''}`;
            // Image from full combo takes final precedence
            if(combo.image_url) {
                currentImage.value = combo.image_url;
            }
        } else {
            // Reset to product defaults if combo is broken
            currentPrice.value = props.product.price;
            originalPrice.value = props.product.original_price;
            currentStock.value = props.product.stock;
            currentReservedStock.value = props.product.reserved_stock;
            currentSku.value = props.product.sku;
            // The `selectedVariants` watcher will handle reverting the image
        }
    }
});


const canAddToCart = computed(() => {
    if (!props.product) return false;
    if (!props.product.variant_combinations || props.product.variant_combinations.length === 0) return true;
    return !!selectedCombination.value;
});

const closeModal = () => emit('update:visible', false);

const addProductToCart = () => {
    if (!isSelectionComplete.value && props.product.variants && Object.keys(props.product.variants).length > 0) {
        return;
    }
    if (!canAddToCart.value) return;

    const data = { product: props.product, variant: selectedCombination.value };
    if (!data.variant && (!props.product.variants || Object.keys(props.product.variants).length === 0)) {
        emit('addToCart', { product: props.product });
    } else if (data.variant) {
        emit('addToCart', data);
    }
    closeModal();
};

const getPromotionSummary = (promo) => {
    const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
    switch (promo.type) {
        case 'ITEM_DISCOUNT': {
            const effect = promo.effects[0];
            if (!effect) return promo.description || 'Descuento especial.';
            if (effect.type === 'PERCENTAGE_DISCOUNT') return `Aplica un ${effect.value}% de descuento.`;
            if (effect.type === 'FIXED_DISCOUNT') return `Aplica un descuento de ${formatCurrency(effect.value)}.`;
            if (effect.type === 'SET_PRICE') return `Precio especial de ${formatCurrency(effect.value)}.`;
            return promo.description || 'Descuento especial aplicado.';
        }
        case 'BOGO': {
            const rule = promo.rules.find(r => r.type === 'REQUIRES_PRODUCT_QUANTITY');
            const effect = promo.effects.find(e => e.type === 'FREE_ITEM');
            if (!rule || !effect || !rule.itemable || !effect.itemable) return promo.description || 'Promoción especial.';
            return `Compra ${rule.value} de "${rule.itemable.name}" y llévate ${effect.value} de "${effect.itemable.name}" gratis.`;
        }
        case 'BUNDLE_PRICE': {
            const effect = promo.effects.find(e => e.type === 'SET_PRICE');
            if (!effect || promo.rules.length === 0) return promo.description || 'Promoción de paquete.';
            const productNames = promo.rules.filter(r => r.type === 'REQUIRES_PRODUCT' && r.itemable).map(r => r.itemable.name).join(' + ');
            return `Paquete (${productNames}) por ${formatCurrency(effect.value)}.`;
        }
        default:
            return promo.description || 'Promoción especial.';
    }
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN'
    }).format(value || 0);
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Detalles del producto"
        class="w-full max-w-lg md:max-w-xl"
        :breakpoints="{ '1199px': '75vw', '575px': '95vw' }"
        :pt="{
            root: { class: 'dark:bg-[#232323] border-none shadow-2xl rounded-3xl overflow-hidden' },
            header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 md:px-8 py-5 md:py-6' },
            title: { class: 'text-xl md:text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0' },
            content: { class: 'dark:bg-[#232323] px-6 md:px-8 py-5 md:py-6' },
            footer: { class: 'dark:bg-[#232323] border-t border-gray-100 dark:border-[#3a3a3a] px-6 md:px-8 py-4 md:py-5' }
        }">
        
        <div v-if="product" class="text-gray-800 dark:text-gray-200">
            
            <!-- GALERÍA DE IMÁGENES -->
            <div class="relative mb-6 group bg-gray-50 dark:bg-[#1a1a1a] rounded-3xl p-4 border border-gray-100 dark:border-[#3a3a3a] h-64 md:h-80 flex items-center justify-center">
                <img :src="currentImage" :alt="product.name" class="w-full h-full object-contain drop-shadow-md transition-all duration-300">
                
                <template v-if="product.general_images && product.general_images.length > 1">
                    <button @click="prevImage" class="absolute left-4 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-white/80 dark:bg-[#232323]/80 backdrop-blur-sm border border-gray-200 dark:border-[#3a3a3a] text-gray-600 dark:text-gray-300 hover:text-primary-500 hover:scale-110 transition-all duration-300 flex items-center justify-center shadow-sm opacity-0 group-hover:opacity-100">
                        <i class="pi pi-chevron-left !text-sm"></i>
                    </button>
                    <button @click="nextImage" class="absolute right-4 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-white/80 dark:bg-[#232323]/80 backdrop-blur-sm border border-gray-200 dark:border-[#3a3a3a] text-gray-600 dark:text-gray-300 hover:text-primary-500 hover:scale-110 transition-all duration-300 flex items-center justify-center shadow-sm opacity-0 group-hover:opacity-100">
                        <i class="pi pi-chevron-right !text-sm"></i>
                    </button>
                    
                    <!-- Indicador de página del carrusel -->
                    <div class="absolute bottom-4 left-1/2 -translate-x-1/2 flex gap-1.5 opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                        <div v-for="(_, index) in product.general_images" :key="index" 
                            class="w-2 h-2 rounded-full transition-colors"
                            :class="carouselIndex === index ? 'bg-primary-500' : 'bg-gray-300 dark:bg-gray-600'">
                        </div>
                    </div>
                </template>
            </div>

            <div class="space-y-6">
                <!-- TÍTULO Y CATEGORÍA -->
                <div>
                    <h2 class="text-2xl md:text-3xl font-light tracking-tight text-gray-900 dark:text-white mb-1.5 m-0 leading-tight">
                        {{ product.name }}
                    </h2>
                    <p class="text-[10px] text-gray-500 uppercase tracking-widest m-0 flex items-center gap-1.5">
                        {{ product.category }}
                    </p>
                </div>

                <!-- PROMOCIONES ACTIVAS -->
                <div v-if="product.promotions && product.promotions.length > 0" class="space-y-2">
                    <div v-for="promo in product.promotions" :key="promo.name"
                        class="bg-red-50 dark:bg-red-900/10 border border-red-100 dark:border-red-900/30 p-3 rounded-2xl flex items-start gap-3">
                        <div class="w-8 h-8 rounded-full bg-red-100 dark:bg-red-900/40 text-red-500 flex items-center justify-center flex-shrink-0">
                            <i class="pi pi-tag !text-xs animate-pulse"></i>
                        </div>
                        <div>
                            <p class="font-medium text-sm text-red-700 dark:text-red-400 m-0 mb-0.5">{{ promo.name }}</p>
                            <p class="text-[11px] text-red-600 dark:text-red-300/80 m-0 leading-relaxed">{{ getPromotionSummary(promo) }}</p>
                        </div>
                    </div>
                </div>

                <!-- SELECTOR DE VARIANTES -->
                <div v-if="product.variants && Object.keys(product.variants).length > 0" class="space-y-4 pt-2">
                    <div v-for="(options, variantName) in product.variants" :key="variantName">
                        <p class="text-[10px] font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0 mb-2.5">
                            Seleccionar {{ variantName }}
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <button v-for="option in options" :key="option.value"
                                @click="selectOption(variantName, option.value)"
                                :disabled="isOptionDisabled(variantName, option.value)"
                                class="text-xs font-bold uppercase tracking-wider px-4 py-2 rounded-full border transition-all duration-300 select-none"
                                :class="[
                                    isOptionSelected(variantName, option.value)
                                        ? 'bg-gray-900 dark:bg-white border-gray-900 dark:border-white text-white dark:text-gray-900 scale-105 shadow-sm'
                                        : 'bg-transparent border-gray-200 dark:border-[#3a3a3a] text-gray-600 dark:text-gray-400 hover:border-gray-400 dark:hover:border-gray-500 disabled:opacity-30 disabled:cursor-not-allowed'
                                ]"
                                v-tooltip.bottom="`Stock: ${option.stock}`">
                                {{ option.value }}
                            </button>
                        </div>
                    </div>
                    
                    <Message v-if="isCombinationInvalid" severity="warn" :closable="false" :pt="{ root: { class: 'dark:!bg-yellow-900/20 dark:!border-yellow-900/50 !rounded-2xl' } }">
                        Esta combinación de opciones no está disponible.
                    </Message>
                </div>

                <!-- PANEL DE PRECIOS Y STOCK (TELEMETRÍA) -->
                <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] grid grid-cols-1 md:grid-cols-2 gap-6">
                    
                    <!-- Precios -->
                    <div class="flex flex-col justify-center border-b md:border-b-0 md:border-r border-gray-200 dark:border-[#3a3a3a] pb-4 md:pb-0 md:pr-6">
                        <span class="text-[10px] uppercase tracking-widest text-gray-500 mb-1">Valor Unitario</span>
                        
                        <div v-if="currentPrice < originalPrice" class="flex flex-col gap-1">
                            <div class="flex items-center gap-2">
                                <span class="text-3xl md:text-4xl font-light tracking-tight text-red-500 m-0 leading-none">
                                    {{ formatCurrency(currentPrice) }}
                                </span>
                            </div>
                            <del class="text-xs text-gray-400 m-0">{{ formatCurrency(originalPrice) }} precio regular</del>
                        </div>
                        
                        <div v-else>
                            <span class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-none">
                                {{ formatCurrency(currentPrice) }}
                            </span>
                        </div>
                    </div>

                    <!-- Detalles (Stock / SKU) -->
                    <div class="flex flex-col justify-center space-y-3">
                        <div class="flex justify-between items-center">
                            <span class="text-[10px] uppercase tracking-widest text-gray-500">Identificador</span>
                            <span class="font-mono text-sm text-gray-900 dark:text-gray-100">{{ currentSku || '--' }}</span>
                        </div>
                        
                        <div class="flex justify-between items-center">
                            <span class="text-[10px] uppercase tracking-widest text-gray-500">Stock actual</span>
                            <span v-if="isComposite" class="font-bold text-[10px] uppercase tracking-wider text-purple-600 dark:text-purple-400 bg-purple-50 dark:bg-purple-900/30 px-2.5 py-1 rounded-full flex items-center gap-1.5 border border-purple-100 dark:border-purple-800">
                                <i class="pi pi-link !text-[8px]"></i> Compuesto
                            </span>
                            <span v-else class="font-mono text-sm font-bold flex items-center gap-1.5" :class="currentStock > 0 ? 'text-green-500' : 'text-red-500'">
                                <span class="w-1.5 h-1.5 rounded-full" :class="currentStock > 0 ? 'bg-green-500 animate-pulse' : 'bg-red-500'"></span>
                                {{ currentStock }} uds.
                            </span>
                        </div>
                        
                        <div v-if="currentReservedStock > 0 && !isComposite" class="flex justify-between items-center">
                            <span class="text-[10px] uppercase tracking-widest text-gray-500">Reservado</span>
                            <span class="font-mono text-xs text-blue-500">- {{ currentReservedStock }} uds.</span>
                        </div>
                    </div>
                </div>

                <!-- COMPONENTES (Para combos) -->
                <div v-if="isComposite" class="pt-2">
                    <p class="text-[10px] uppercase tracking-widest text-gray-500 mb-3 flex items-center gap-2 m-0">
                        <i class="pi pi-list !text-[10px]"></i> Contenido del paquete
                    </p>
                    <ul class="space-y-2 m-0 p-0 list-none">
                        <li v-for="component in product.components" :key="component.id" 
                            class="flex justify-between items-center bg-gray-50 dark:bg-[#1a1a1a] p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                            <div class="flex items-center gap-3">
                                <div class="w-6 h-6 rounded-full bg-white dark:bg-[#232323] text-gray-400 flex items-center justify-center border border-gray-200 dark:border-[#3a3a3a]">
                                    <i class="pi pi-box !text-[10px]"></i>
                                </div>
                                <span class="font-medium text-sm text-gray-900 dark:text-gray-100 m-0">{{ component.componentable?.name || 'Componente' }}</span>
                            </div>
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400 bg-white dark:bg-[#232323] px-2 py-1 rounded-lg border border-gray-200 dark:border-[#3a3a3a]">
                                x{{ component.quantity }}
                            </span>
                        </li>
                    </ul>
                </div>

                <!-- DESCRIPCIÓN -->
                <div v-if="product.description" class="pt-2">
                    <p class="text-[10px] uppercase tracking-widest text-gray-500 mb-2 m-0">Acerca del producto</p>
                    <div class="text-sm text-gray-600 dark:text-gray-400 leading-relaxed prose-sm dark:prose-invert max-w-none"
                        v-html="product.description"></div>
                </div>
            </div>
        </div>
        
        <template #footer>
            <div class="flex items-center justify-end gap-3 w-full">
                <Button label="Cancelar" severity="secondary" text @click="closeModal" class="!rounded-xl !uppercase !tracking-widest !text-[11px] !font-bold" />
                <Button label="Añadir al carrito" icon="pi pi-shopping-cart" @click="addProductToCart"
                    :disabled="!canAddToCart"
                    class="!rounded-xl !uppercase !tracking-widest !text-[11px] !font-bold !py-3 px-6" />
            </div>
        </template>
    </Dialog>
</template>