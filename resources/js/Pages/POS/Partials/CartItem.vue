<script setup>
import { ref, watch, computed } from 'vue';
import { usePermissions } from '@/Composables';
import { FireIcon } from '@heroicons/vue/24/solid';

const props = defineProps({
    item: Object,
    appliedCartPromoNames: {
        type: Set,
        default: () => new Set(),
    }
});

const emit = defineEmits(['updateQuantity', 'updatePrice', 'removeItem']);

const { hasPermission } = usePermissions();

const quantity = ref(props.item.quantity);
const price = ref(props.item.price);
const isEditingPrice = ref(false);

watch(quantity, (newQuantity) => {
    if (newQuantity !== props.item.quantity) {
        emit('updateQuantity', { itemId: props.item.cartItemId, quantity: newQuantity });
    }
});

const applyPriceChange = () => {
    if (price.value !== props.item.price) {
        emit('updatePrice', { itemId: props.item.cartItemId, price: price.value });
    }
    isEditingPrice.value = false;
}

const cancelPriceEdit = () => {
    price.value = props.item.price;
    isEditingPrice.value = false;
}

watch(() => props.item.quantity, (newVal) => {
    if (quantity.value !== newVal) {
        quantity.value = newVal;
    }
});

watch(() => props.item.price, (newVal) => {
    if (price.value !== newVal) {
        price.value = newVal;
    }
});

// --- MEJORA: Lógica de Promociones ---
const promoPopover = ref();
const togglePromoPopover = (event) => {
    promoPopover.value.toggle(event);
};

const isItemDiscountApplied = computed(() => props.item.original_price && props.item.price < props.item.original_price);

const isCartPromoAppliedToItem = computed(() => {
    if (!props.item.promotions || props.item.promotions.length === 0) {
        return false;
    }
    return props.item.promotions.some(p => props.appliedCartPromoNames.has(p.name));
});

const isPromoActive = computed(() => isItemDiscountApplied.value || isCartPromoAppliedToItem.value);

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN'
    }).format(value || 0);
};

const getPromotionSummary = (promo) => {
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
            return `Compra ${rule.value} y llévate ${effect.value} gratis.`;
        }
        case 'BUNDLE_PRICE': {
            const effect = promo.effects.find(e => e.type === 'SET_PRICE');
            if (!effect || promo.rules.length === 0) return promo.description || 'Promoción de paquete.';
            // CORRECCIÓN: Se cambió 'REQUIRES_PRODUCT_QUANTITY' por 'REQUIRES_PRODUCT' que es el tipo correcto para esta promoción.
            const productNames = promo.rules.filter(r => r.type === 'REQUIRES_PRODUCT' && r.itemable).map(r => `${r.value} x ${r.itemable.name}`).join(' + ');
            return `Paquete (${productNames}) por ${formatCurrency(effect.value)}.`;
        }
        default:
            return promo.description || 'Promoción especial.';
    }
};

</script>

<template>
    <div
        class="flex gap-4 relative bg-white dark:bg-gray-900 p-3 rounded-xl border border-[#D9D9D9] dark:border-gray-700">
        <img :src="item.image" :alt="item.name" class="size-16 rounded-[10px] object-contain bg-[#f2f2f2]">
        <div class="flex-grow">
            <p class="font-bold text-sm leading-tight text-[#373737] dark:text-gray-200 w-[87%]">{{ item.name }}</p>
            <div v-if="isEditingPrice" class="flex items-center gap-1 mt-1">
                <InputNumber fluid v-model.number="price" mode="currency" currency="MXN"
                    locale="es-MX"
                    class="!w-24 !h-[2rem]" @keyup.enter="applyPriceChange" @keyup.esc="cancelPriceEdit" />
                <Button icon="pi pi-check" variant="outlined" rounded size="small" @click="applyPriceChange" class="!size-6" />
                <Button icon="pi pi-times" variant="outlined" rounded size="small" severity="secondary" @click="cancelPriceEdit" class="!size-6" />
            </div>
            <div v-else class="flex items-center gap-2 mt-1">
                <p v-if="!isItemDiscountApplied" class="text-sm font-light text-[#373737] dark:text-gray-400 m-0">
                    {{ formatCurrency(item.price) }}
                </p>
                <div v-else class="flex items-center gap-2">
                    <del class="text-xs text-gray-400">{{ formatCurrency(item.original_price) }}</del>
                    <p class="text-sm font-bold text-[#373737] dark:text-gray-100">{{ formatCurrency(item.price) }}</p>
                </div>
                <Button v-if="hasPermission('pos.edit_prices')" @click="isEditingPrice = true" icon="pi pi-pencil"
                    rounded variant="outlined" severity="secondary" class="!size-6" size="small" />
            </div>

            <p class="text-xs text-gray-500"
                v-if="item.selectedVariant && Object.keys(item.selectedVariant).length > 0">
                <span v-for="(value, key, index) in item.selectedVariant" :key="key">
                    <span class="capitalize">{{ key }}</span>: {{ value }}{{ index <
                        Object.keys(item.selectedVariant).length - 1 ? ' / ' : '' }} </span>
            </p>

            <div class="flex justify-between items-end mt-2">
                <InputNumber v-model="quantity" showButtons :min="1"
                    :inputStyle="{ width: '5rem', height: '2rem' }" size="small" />
                <div class="flex items-center gap-1">
                    <div v-if="item.promotions && item.promotions.length > 0">
                        <button @click="togglePromoPopover($event)" v-tooltip.bottom="'Ver promociones'">
                            <FireIcon class="size-5"
                                :class="isPromoActive ? 'text-[#AE080B] animate-pulse' : 'text-gray-400'" />
                        </button>
                        <Popover ref="promoPopover">
                            <div class="p-3 w-60">
                                <h4 class="font-bold text-base mb-2 border-b pb-2">Promociones disponibles</h4>
                                <div class="space-y-3 max-h-48 overflow-y-auto">
                                    <div v-for="promo in item.promotions" :key="promo.name" class="text-sm">
                                        <p class="font-semibold m-0">{{ promo.name }}</p>
                                        <p class="text-xs text-gray-600 m-0">{{ getPromotionSummary(promo) }}</p>
                                    </div>
                                </div>
                            </div>
                        </Popover>
                    </div>
                    <p class="font-bold text-gray-800 dark:text-gray-100 m-0">
                        {{ formatCurrency(item.price * quantity) }}
                    </p>
                </div>
            </div>
        </div>
        <Button @click="$emit('removeItem', item.cartItemId)" icon="pi pi-trash" rounded variant="outlined" severity="danger"
            size="small" class="!size-7 !absolute top-1 right-1" />
    </div>
</template>