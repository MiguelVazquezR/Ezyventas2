<script setup>
import { ref, computed, watch } from 'vue';

const props = defineProps({
    product: Object,
});

const emit = defineEmits(['showDetails', 'addToCart']);

const promoPopover = ref();
const togglePromoPopover = (event) => {
    promoPopover.value.toggle(event);
};

// --- Lógica para selección de variantes en la tarjeta ---
const cardSelectedVariants = ref({});
const displayPrice = ref(props.product.price);
const displayStock = ref(props.product.stock);
const hasVariants = computed(() => props.product.variants && Object.keys(props.product.variants).length > 0);

const selectCardOption = (variantName, value) => {
    if (cardSelectedVariants.value[variantName] === value) {
        delete cardSelectedVariants.value[variantName];
    } else {
        cardSelectedVariants.value[variantName] = value;
    }
};

const isCardOptionSelected = (variantName, value) => {
    return cardSelectedVariants.value[variantName] === value;
};

const isCardOptionDisabled = (variantName, optionValue) => {
    if (!props.product || !props.product.variant_combinations) return false;

    const otherSelectedVariants = { ...cardSelectedVariants.value };
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

const cardSelectedCombination = computed(() => {
    if (!hasVariants.value) return null;
    const variantTypes = Object.keys(props.product.variants);
    if (Object.keys(cardSelectedVariants.value).length !== variantTypes.length) return null;

    return props.product.variant_combinations.find(combo =>
        variantTypes.every(name => combo.attributes[name] === cardSelectedVariants.value[name])
    );
});

// --- MEJORA: Imagen dinámica como propiedad computada ---
const displayImage = computed(() => {
    // 1. Prioridad: Imagen de la combinación completa
    if (cardSelectedCombination.value && cardSelectedCombination.value.image_url) {
        return cardSelectedCombination.value.image_url;
    }

    // 2. Prioridad: Imagen de cualquier opción seleccionada
    if (props.product.variant_combinations && Object.keys(cardSelectedVariants.value).length > 0) {
        const selectedKeys = Object.keys(cardSelectedVariants.value);
        for (let i = selectedKeys.length - 1; i >= 0; i--) {
            const variantName = selectedKeys[i];
            const optionValue = cardSelectedVariants.value[variantName];

            const comboWithImage = props.product.variant_combinations.find(
                combo => combo.attributes[variantName] === optionValue && combo.image_url
            );
            if (comboWithImage) {
                return comboWithImage.image_url;
            }
        }
    }

    // 3. Fallback: Imagen principal del producto
    return props.product.image;
});


watch(cardSelectedCombination, (combo) => {
    if (combo) {
        displayPrice.value = props.product.price + combo.price_modifier;
        displayStock.value = combo.stock;
    } else {
        displayPrice.value = props.product.price;
        displayStock.value = props.product.stock;
    }
});

watch(() => props.product, () => {
    cardSelectedVariants.value = {};
    displayPrice.value = props.product.price;
    displayStock.value = props.product.stock;
}, { deep: true });


const handlePrimaryAction = () => {
    if (hasVariants.value) {
        if (cardSelectedCombination.value) {
            emit('addToCart', { product: props.product, variant: cardSelectedCombination.value });
        } else {
            emit('showDetails', props.product);
        }
    } else {
        emit('addToCart', { product: props.product });
    }
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
            const productRules = promo.rules.filter(r => r.itemable && (r.type === 'REQUIRES_PRODUCT_QUANTITY' || r.type === 'REQUIRES_PRODUCT'));

            if (!effect || productRules.length === 0) return promo.description || 'Promoción de paquete.';

            const productDetails = productRules.map(r => {
                const quantity = r.value || 1; // The rule value indicates the quantity
                return `${quantity} x ${r.itemable.name}`;
            }).join(' + ');

            return `Paquete (${productDetails}) por ${formatCurrency(effect.value)}.`;
        }
        default:
            return promo.description || 'Promoción especial.';
    }
};
</script>

<template>
    <div
        class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden flex flex-col bg-white dark:bg-gray-800 transition-shadow hover:shadow-lg">
        <div class="relative">
            <img :src="displayImage" :alt="product.name" class="w-full h-40 object-contain">

            <div v-if="product.promotions && product.promotions.length > 0" @click="togglePromoPopover"
                class="absolute top-2 right-2 cursor-pointer" v-tooltip.bottom="'Ver detalles de la promoción'">
                <Badge value="Promoción" severity="danger"></Badge>
            </div>
            <div
                class="absolute bottom-2 right-2 cursor-pointer" v-tooltip.bottom="'Ver detalles'">
                <Button icon="pi pi-arrows-alt" rounded text severity="secondary"
                    class="bg-white/50 dark:bg-black/50" @click="emit('showDetails', product)"
                    v-tooltip.bottom="'Ver detalles'" />
            </div>

            <Popover ref="promoPopover">
                <div class="p-3 w-64">
                    <h4 class="font-bold text-md mb-2 border-b pb-2">Promociones Disponibles</h4>
                    <div class="space-y-3 max-h-48 overflow-y-auto">
                        <div v-for="promo in product.promotions" :key="promo.name" class="text-sm">
                            <p class="font-semibold">{{ promo.name }}</p>
                            <p class="text-xs text-gray-600">{{ getPromotionSummary(promo) }}</p>
                        </div>
                    </div>
                </div>
            </Popover>

            <Badge :value="`${displayStock} en stock`" :severity="displayStock > 0 ? 'contrast' : 'danger'" class="absolute top-2 left-2"></Badge>
        </div>
        <div class="p-4 flex flex-col flex-grow">
            <h3 class="font-bold text-gray-800 dark:text-gray-200 text-lg h-12 overflow-hidden">{{ product.name }}</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">{{ product.category }}</p>

            <div class="space-y-2 my-2 min-h-[3rem]">
                 <div v-if="hasVariants" class="space-y-3">
                    <div v-for="(options, variantName) in product.variants" :key="variantName">
                        <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 capitalize">{{ variantName }}</p>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-for="option in options"
                                :key="option.value"
                                :label="option.value"
                                :outlined="!isCardOptionSelected(variantName, option.value)"
                                severity="secondary"
                                class="p-button-sm !text-xs !py-1 !px-2"
                                @click="selectCardOption(variantName, option.value)"
                                :disabled="isCardOptionDisabled(variantName, option.value)"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <div class="mb-3 mt-auto">
                <div v-if="displayPrice < product.original_price" class="flex items-baseline gap-2">
                    <p class="text-xl font-semibold text-red-600">${{ displayPrice.toFixed(2) }}</p>
                    <del class="text-md text-gray-500">${{ product.original_price.toFixed(2) }}</del>
                </div>
                <p v-else class="text-xl font-semibold text-gray-900 dark:text-gray-100">${{ displayPrice.toFixed(2) }}
                </p>
            </div>

            <Button
                :label="hasVariants && !cardSelectedCombination ? 'Elegir opciones' : 'Agregar al carrito'"
                icon="pi pi-plus" severity="warning" class="w-full" @click="handlePrimaryAction" />
        </div>
    </div>
</template>