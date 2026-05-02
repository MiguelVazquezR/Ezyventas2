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

const isComposite = computed(() => props.product.components && props.product.components.length > 0);

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
    <div class="relative border border-gray-100 dark:border-[#3a3a3a] rounded-3xl overflow-hidden flex flex-col bg-white dark:bg-[#1a1a1a] transition-all duration-300 hover:shadow-xl hover:-translate-y-1 hover:border-primary-500/30 group h-full">
                
        <!-- CONTENEDOR DE IMAGEN (Altura reducida a h-36) -->
        <div class="m-2 relative group">
            <div class="bg-gray-50 dark:bg-[#232323] rounded-2xl flex items-center justify-center h-36 overflow-hidden p-3 border border-transparent group-hover:border-gray-100 dark:group-hover:border-[#2a2a2a] transition-colors">
                <img :src="displayImage" :alt="product.name" class="w-full h-full object-contain drop-shadow-sm transition-transform duration-500 group-hover:scale-105">
            </div>
            
            <!-- BADGE: Cantidad en Carrito -->
            <div v-if="quantityInCart > 0" 
                 class="absolute bottom-2 left-2 bg-primary-500 text-white text-[10px] uppercase tracking-widest font-bold px-3 py-1 rounded-full shadow-[0_0_10px_rgba(246,140,15,0.4)] flex items-center gap-1.5 animate-in fade-in zoom-in duration-300">
                <i class="pi pi-shopping-cart !text-[10px]"></i>
                <span>{{ quantityInCart }} en carrito</span>
            </div>

            <!-- BADGE: Dinámico / Combo -->
            <div v-if="isComposite" class="absolute bottom-2 right-2 z-10">
                <span class="bg-purple-500 text-white text-[9px] uppercase tracking-widest font-bold px-2 py-1 rounded-full flex items-center gap-1 shadow-sm">
                    <i class="pi pi-link !text-[9px]"></i> Combo
                </span>
            </div>

            <!-- BADGE: Stock (Más Notorio y de Alto Contraste) -->
            <div class="absolute top-2 left-2 z-10">
                <span class="text-[10px] uppercase tracking-widest font-bold px-3 py-1.5 rounded-full flex items-center gap-1.5 shadow-sm border"
                    :class="displayStock > 0 
                        ? 'bg-green-100 border-green-200 text-green-700 dark:bg-green-900/40 dark:border-green-800 dark:text-green-400' 
                        : 'bg-red-100 border-red-200 text-red-700 dark:bg-red-900/40 dark:border-red-800 dark:text-red-400'">
                    <span class="w-2 h-2 rounded-full shadow-inner animate-pulse" :class="displayStock > 0 ? 'bg-green-500' : 'bg-red-500'"></span>
                    {{ displayStock }} disp. 
                    <span v-if="displayReservedStock > 0" class="opacity-70 ml-1 border-l pl-1 border-current">
                        {{ displayReservedStock }} apart.
                    </span>
                </span>
            </div>

            <!-- BOTÓN: Expandir Detalles -->
            <button class="absolute top-2 right-2 bg-white/80 dark:bg-[#232323]/80 backdrop-blur-md text-gray-700 dark:text-gray-300 rounded-full w-7 h-7 border border-gray-200 dark:border-[#3a3a3a] flex items-center justify-center opacity-0 group-hover:opacity-100 transition-all duration-300 hover:bg-white dark:hover:bg-gray-800 hover:scale-110 shadow-sm"
                @click="emit('showDetails', product)" v-tooltip.bottom="'Ver detalles'">
                <i class="pi pi-expand !text-[10px]"></i>
            </button>
        </div>
        
        <!-- CUERPO DE LA TARJETA (Paddings y Gaps reducidos) -->
        <div class="px-4 py-2 flex flex-col flex-grow">
            
            <!-- Título y Categoría -->
            <div class="mb-3">
                <h3 class="font-medium text-gray-900 dark:text-white text-[15px] leading-tight m-0 mb-1 line-clamp-2">{{ product.name }}</h3>
                <p class="text-[9px] text-gray-500 uppercase tracking-widest m-0 flex items-center gap-1.5 truncate">
                    <span v-if="product.sku" class="flex items-center gap-1"><i class="pi pi-barcode !text-[9px]"></i> {{ product.sku }}</span>
                    <span v-if="product.sku && product.category?.name" class="opacity-50">•</span>
                    <span>{{ product.category?.name || product.category || 'General' }}</span>
                </p>
            </div>

            <!-- Variantes (Más compactas) -->
            <div class="space-y-2 mb-3 mt-auto">
                <div v-if="hasVariants" class="space-y-2">
                    <div v-for="(options, variantName) in product.variants" :key="variantName">
                        <p class="text-[9px] font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0 mb-1.5">{{ variantName }}</p>
                        <div class="flex flex-wrap gap-1.5">
                            <button v-for="option in options" :key="option.value || option"
                                @click="selectCardOption(variantName, option.value || option)"
                                :disabled="isCardOptionDisabled(variantName, option.value || option)"
                                class="text-[9px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-full border transition-all duration-300 select-none"
                                :class="[
                                    isCardOptionSelected(variantName, option.value || option)
                                        ? 'bg-gray-900 dark:bg-white border-gray-900 dark:border-white text-white dark:text-gray-900 scale-105 shadow-sm'
                                        : 'bg-transparent border-gray-200 dark:border-[#3a3a3a] text-gray-600 dark:text-gray-400 hover:border-gray-400 dark:hover:border-gray-500 disabled:opacity-30 disabled:cursor-not-allowed'
                                ]">
                                {{ option.value || option }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Precio y Acciones (Gap reducido) -->
            <div class="mt-auto pt-3 border-t border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3">
                
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div v-if="displayPrice < product.original_price" class="flex flex-col">
                            <del class="text-[10px] text-gray-400 m-0 leading-none mb-0.5">{{ formatCurrency(product.original_price) }}</del>
                            <p class="text-xl font-light tracking-tight text-green-500 m-0 leading-none">{{ formatCurrency(displayPrice) }}</p>
                        </div>
                        <p v-else class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-none">
                            {{ formatCurrency(displayPrice) }}
                        </p>
                        
                        <!-- Popover Promociones -->
                        <button v-if="product.promotions && product.promotions.length > 0" @click="togglePromoPopover"
                            class="ml-1 w-6 h-6 rounded-full bg-red-50 dark:bg-red-900/20 text-red-500 flex items-center justify-center hover:bg-red-100 dark:hover:bg-red-900/40 transition-colors" v-tooltip.top="'Ver promoción'">
                            <FireIcon class="size-3.5 animate-pulse" />
                        </button>
                        <Popover ref="promoPopover" :pt="{ root: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-2xl shadow-xl' } }">
                            <div class="p-4 w-72">
                                <h4 class="font-medium text-gray-900 dark:text-white m-0 mb-3 tracking-tight border-b border-gray-100 dark:border-[#3a3a3a] pb-2">Promociones activas</h4>
                                <div class="space-y-4 max-h-48 overflow-y-auto custom-scrollbar pr-2">
                                    <div v-for="promo in product.promotions" :key="promo.name">
                                        <p class="text-xs font-bold text-gray-800 dark:text-gray-200 uppercase tracking-widest m-0 mb-1">{{ promo.name }}</p>
                                        <p class="text-xs text-gray-600 dark:text-gray-400 m-0 leading-relaxed">{{ getPromotionSummary(promo) }}</p>
                                    </div>
                                </div>
                            </div>
                        </Popover>
                    </div>
                </div>

                <Button :label="hasVariants && !cardSelectedCombination ? 'Elegir opciones' : 'Añadir'"
                    :icon="hasVariants && !cardSelectedCombination ? 'pi pi-sliders-h' : 'pi pi-plus'" 
                    :severity="hasVariants && !cardSelectedCombination ? 'secondary' : 'primary'"
                    class="w-full !rounded-xl !text-[11px] !uppercase !tracking-widest !font-bold !py-2.5" 
                    @click="handlePrimaryAction" />
            </div>

        </div>
    </div>
</template>