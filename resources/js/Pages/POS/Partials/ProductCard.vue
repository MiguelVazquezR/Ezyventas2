<script setup>
import { ref, computed, watch } from 'vue';
import { FireIcon } from '@heroicons/vue/24/solid';

const props = defineProps({
    product: Object,
    cartItems: { type: Array, default: () => [] } // <--- Nueva prop recibida
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
const displayReservedStock = ref(props.product.reserved_stock);
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

// --- NUEVA LÓGICA: Cantidad en Carrito ---
const quantityInCart = computed(() => {
    if (!props.cartItems || props.cartItems.length === 0) return 0;

    // 1. Si el producto tiene variantes y el usuario ya seleccionó una combinación válida en la tarjeta
    if (hasVariants.value && cardSelectedCombination.value) {
        const item = props.cartItems.find(i => 
            i.id === props.product.id && 
            i.product_attribute_id === cardSelectedCombination.value.id
        );
        return item ? item.quantity : 0;
    }

    // 2. Si no tiene variantes (producto simple) O no ha seleccionado variante aún
    //    En este caso, sumamos TODAS las instancias de este producto en el carrito (incluyendo variantes mixtas si las hubiera)
    //    Esto da un feedback general de "ya tienes este producto" aunque no especifique cuál variante.
    const items = props.cartItems.filter(i => i.id === props.product.id);
    return items.reduce((sum, i) => sum + i.quantity, 0);
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
        displayReservedStock.value = combo.reserved_stock;
    } else {
        displayPrice.value = props.product.price;
        displayStock.value = props.product.stock;
        displayReservedStock.value = props.product.reserved_stock;
    }
});

watch(() => props.product, () => {
    cardSelectedVariants.value = {};
    displayPrice.value = props.product.price;
    displayStock.value = props.product.stock;
    displayReservedStock.value = props.product.reserved_stock;
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
            const productRules = promo.rules.filter(r => r.itemable && (r.type === 'REQUIRES_PRODUCT' || r.type === 'REQUIRES_PRODUCT'));

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

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN'
    }).format(value || 0);
};
</script>

<template>
    <div
        class="relative border border-gray-200 dark:border-gray-700 rounded-[15px] overflow-hidden flex flex-col bg-white dark:bg-gray-800 transition-shadow hover:shadow-lg">
        <div class="m-3 relative">
            <img :src="displayImage" :alt="product.name" class="w-full h-40 object-contain bg-[#F2F2F2] rounded-xl">
            
            <!-- INDICADOR DE CANTIDAD EN CARRITO -->
            <div v-if="quantityInCart > 0" 
                 class="absolute bottom-2 left-2 bg-blue-600 text-white text-xs font-bold px-2 py-1 rounded-full shadow-md z-10 flex items-center gap-1 animate-in fade-in zoom-in duration-300">
                <i class="pi pi-shopping-cart !text-xs"></i>
                <span>{{ quantityInCart }} en carrito</span>
            </div>
            <span
                class="absolute top-0 left-0 rounded-none rounded-tl-[15px] rounded-br-[15px] text-sm text-white dark:text-gray-900 px-2 py-1"
                :class="displayStock > 0 ? 'bg-[#122C3C] dark:bg-gray-400' : 'bg-red-600 dark:bg-red-400'">
                {{ displayStock }} disponibles
                <span v-if="displayReservedStock > 0">
                    | {{ displayReservedStock }} apartados
                </span>
            </span>
            <button class="absolute top-1 right-1 bg-[#5c5c5c]/70 dark:bg-black/50 text-white rounded-[6px] size-7 border border-white flex items-center justify-center"
                @click="emit('showDetails', product)" v-tooltip.bottom="'Ver detalles'">
                <i class="pi pi-arrow-up-right-and-arrow-down-left-from-center !text-xs"></i>
            </button>
        </div>
        <div class="px-4 py-2 flex flex-col flex-grow">
            <h3 class="font-bold text-gray-800 dark:text-gray-200 text-lg overflow-hidden m-0">{{ product.name }}</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">{{ product.category }}</p>
            <div class="space-y-2 my-2 min-h-[1rem]">
                <div v-if="hasVariants" class="space-y-3">
                    <div v-for="(options, variantName) in product.variants" :key="variantName">
                        <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 capitalize">{{
                            variantName }}</p>
                        <div class="flex flex-wrap gap-2">
                            <Button v-for="option in options" :key="option.value" :label="option.value"
                                :outlined="!isCardOptionSelected(variantName, option.value)" severity="contrast"
                                class="p-button-sm !text-xs !py-1 !px-2"
                                @click="selectCardOption(variantName, option.value)"
                                :disabled="isCardOptionDisabled(variantName, option.value)" />
                        </div>
                    </div>
                </div>
            </div>

            <div class="mb-3 mt-auto flex items-center gap-1">
                <div v-if="displayPrice < product.original_price" class="flex items-baseline gap-2">
                    <p class="text-xl font-semibold text-green-600 m-0">{{ formatCurrency(displayPrice) }}</p>
                    <del class="text-md text-gray-500">{{ formatCurrency(product.original_price) }}</del>
                </div>
                <p v-else class="text-xl font-semibold text-gray-900 dark:text-gray-100 m-0">
                    {{ formatCurrency(displayPrice) }}
                </p>
                <button v-if="product.promotions && product.promotions.length > 0" @click="togglePromoPopover"
                    class="cursor-pointer" v-tooltip.bottom="'Ver detalles de la promoción'">
                    <FireIcon class="size-5 text-[#AE080B] dark:text-red-400 animate-pulse" />
                </button>
                <Popover ref="promoPopover">
                    <div class="p-3 w-64">
                        <h4 class="font-bold text-lg mb-2 border-b pb-2">Promociones Disponibles</h4>
                        <div class="space-y-3 max-h-48 overflow-y-auto">
                            <div v-for="promo in product.promotions" :key="promo.name" class="text-sm">
                                <p class="font-semibold m-0">{{ promo.name }}</p>
                                <p class="text-xs text-gray-600 m-0">{{ getPromotionSummary(promo) }}</p>
                            </div>
                        </div>
                    </div>
                </Popover>
            </div>

            <Button :label="hasVariants && !cardSelectedCombination ? 'Elegir opciones' : 'Agregar al carrito'"
                icon="pi pi-plus" severity="warning" class="w-full" rounded size="small" @click="handlePrimaryAction" />
        </div>
    </div>
</template>