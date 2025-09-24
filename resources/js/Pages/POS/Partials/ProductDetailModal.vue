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
const currentSku = ref('');
const currentImage = ref('');
const originalPrice = ref(0);

watch(() => props.product, (newProduct) => {
    selectedVariants.value = {};
    if (newProduct) {
        currentPrice.value = newProduct.price;
        originalPrice.value = newProduct.original_price;
        currentStock.value = newProduct.stock;
        currentSku.value = newProduct.sku;
        currentImage.value = newProduct.image;
    }
}, { deep: true });

const selectOption = (variantName, value) => {
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

watch(selectedCombination, (combo) => {
    if (props.product) {
        if (combo) {
            currentPrice.value = props.product.price + combo.price_modifier;
            originalPrice.value = props.product.original_price + combo.price_modifier;
            currentStock.value = combo.stock;
            currentSku.value = `${props.product.sku}-${combo.sku_suffix}`;
            currentImage.value = combo.image_url || props.product.image;
        } else {
            currentPrice.value = props.product.price;
            originalPrice.value = props.product.original_price;
            currentStock.value = props.product.stock;
            currentSku.value = props.product.sku;
            currentImage.value = props.product.image;
        }
    }
});

const canAddToCart = computed(() => {
    if (!props.product) return false;
    if (!props.product.variant_combinations || props.product.variant_combinations.length === 0) return props.product.stock > 0;
    return selectedCombination.value && selectedCombination.value.stock > 0;
});

const closeModal = () => emit('update:visible', false);

const addProductToCart = () => {
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
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Detalles del Producto"
        :style="{ width: '450px' }">
        <div v-if="product" class="text-gray-800 dark:text-gray-200">
            <div class="relative mb-4">
                <img :src="currentImage" :alt="product.name" class="w-full h-64 object-cover rounded-md">
            </div>
            <div>
                <h2 class="text-2xl font-bold mb-1">{{ product.name }}</h2>
                <p class="text-md text-gray-500 dark:text-gray-400 mb-4">{{ product.category }}</p>

                <!-- SECCIÓN DE PROMOCIONES -->
                <div v-if="product.promotions && product.promotions.length > 0" class="mb-4 space-y-2">
                    <div v-for="promo in product.promotions" :key="promo.name"
                        class="bg-green-100 dark:bg-green-900/50 text-green-800 dark:text-green-200 p-3 rounded-lg">
                        <p class="font-bold text-sm"><i class="pi pi-tag mr-2"></i>{{ promo.name }}</p>
                        <p class="text-xs mt-1">{{ getPromotionSummary(promo) }}</p>
                    </div>
                </div>

                <!-- Selector de Variantes -->
                <div v-if="product.variants && Object.keys(product.variants).length > 0" class="space-y-4 mb-4">
                    <div v-for="(options, variantName) in product.variants" :key="variantName">
                        <p class="text-sm font-semibold text-gray-600 dark:text-gray-300 mb-2 capitalize">{{ variantName
                            }}</p>
                        <div class="flex flex-wrap gap-2">
                            <Button v-for="option in options" :key="option.value" :label="option.value"
                                :outlined="!isOptionSelected(variantName, option.value)" severity="secondary"
                                size="small" @click="selectOption(variantName, option.value)"
                                v-tooltip.bottom="`Stock: ${option.stock}`" />
                        </div>
                    </div>
                    <Message v-if="isCombinationInvalid" severity="warn" :closable="false">Esta combinación de variantes
                        no existe.</Message>
                </div>

                <!-- Info Dinámica -->
                <div class="bg-gray-100 dark:bg-gray-800 p-4 rounded-lg">
                    <div v-if="currentPrice < originalPrice" class="flex justify-between items-baseline">
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
                    <div class="flex justify-between items-center text-sm mt-2"><span
                            class="text-gray-500">SKU:</span><span class="font-medium font-mono">{{ currentSku }}</span>
                    </div>
                    <div class="flex justify-between items-center text-sm"><span
                            class="text-gray-500">Stock:</span><span class="font-bold"
                            :class="currentStock > 0 ? 'text-green-600' : 'text-red-600'">{{ currentStock }}</span>
                    </div>
                </div>
                <Message v-if="selectedCombination && selectedCombination.stock <= 0" severity="error" :closable="false"
                    class="mt-4">Esta variante no tiene stock.</Message>

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