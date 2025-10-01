<script setup>
import { ref, watch, computed } from 'vue';
import { usePermissions } from '@/Composables';

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
            const productNames = promo.rules.filter(r => r.type === 'REQUIRES_PRODUCT_QUANTITY' && r.itemable).map(r => `${r.value} x ${r.itemable.name}`).join(' + ');
            return `Paquete (${productNames}) por ${formatCurrency(effect.value)}.`;
        }
        default:
            return promo.description || 'Promoción especial.';
    }
};

</script>

<template>
    <div
        class="flex gap-4 relative bg-white dark:bg-gray-900 p-3 rounded-lg border border-gray-200 dark:border-gray-700">
        <Button @click="$emit('removeItem', item.cartItemId)" icon="pi pi-trash" rounded text severity="danger"
            size="small" class="absolute top-1 right-1" />
        <img :src="item.image" :alt="item.name" class="w-16 h-16 rounded-md object-cover">
        <div class="flex-grow">
            <p class="font-semibold text-sm leading-tight text-gray-800 dark:text-gray-200">{{ item.name }}</p>

            <!-- Precio (Editable) -->
            <div v-if="isEditingPrice" class="flex items-center gap-2 mt-1">
                <InputText v-model.number="price" mode="decimal" :minFractionDigits="2" :maxFractionDigits="2"
                    class="p-inputtext-sm w-24" @keyup.enter="applyPriceChange" @keyup.esc="cancelPriceEdit" />
                <Button icon="pi pi-check" text rounded size="small" @click="applyPriceChange" />
                <Button icon="pi pi-times" text rounded size="small" severity="secondary" @click="cancelPriceEdit" />
            </div>
            <div v-else class="flex items-center gap-2 mt-1">
                <!-- MEJORA: Lógica de visualización de precios con descuento y flama -->
                <p v-if="!isItemDiscountApplied" class="text-sm text-gray-600 dark:text-gray-400">
                    {{ formatCurrency(item.price) }}
                </p>
                <div v-else class="flex items-center gap-2">
                    <del class="text-xs text-gray-400">{{ formatCurrency(item.original_price) }}</del>
                    <p class="text-sm font-bold text-red-500">{{ formatCurrency(item.price) }}</p>
                </div>
                
                <div v-if="item.promotions && item.promotions.length > 0">
                    <i class="pi pi-bolt cursor-pointer" 
                        :class="isPromoActive ? 'text-red-500 animate-pulse' : 'text-gray-400'"
                        @click="togglePromoPopover($event)"
                        v-tooltip.bottom="'Ver promociones'"></i>
                    <Popover ref="promoPopover">
                       <div class="p-3 w-60">
                            <h4 class="font-bold text-md mb-2 border-b pb-2">Promociones Disponibles</h4>
                            <div class="space-y-3 max-h-48 overflow-y-auto">
                                <div v-for="promo in item.promotions" :key="promo.name" class="text-sm">
                                    <p class="font-semibold">{{ promo.name }}</p>
                                    <p class="text-xs text-gray-600">{{ getPromotionSummary(promo) }}</p>
                                </div>
                            </div>
                        </div>
                    </Popover>
                </div>
                
                <Button v-if="hasPermission('pos.edit_prices')" @click="isEditingPrice = true" icon="pi pi-pencil" rounded text severity="secondary" style="width: 1.5rem; height: 1.5rem" />
            </div>

            <p class="text-xs text-gray-500"
                v-if="item.selectedVariant && Object.keys(item.selectedVariant).length > 0">
                <span v-for="(value, key, index) in item.selectedVariant" :key="key">
                    <span class="capitalize">{{ key }}</span>: {{ value }}{{ index <
                        Object.keys(item.selectedVariant).length - 1 ? ' / ' : '' }} </span>
            </p>

            <div class="flex justify-between items-center mt-2">
                <InputNumber v-model="quantity" showButtons buttonLayout="horizontal" :min="1" :max="item.stock > 0 ? item.stock : undefined"
                    decrementButtonClass="p-button-secondary" incrementButtonClass="p-button-secondary"
                    incrementButtonIcon="pi pi-plus" decrementButtonIcon="pi pi-minus"
                    :inputStyle="{ width: '3rem', textAlign: 'center' }" />
                <p class="font-bold text-gray-800 dark:text-gray-100">{{ formatCurrency(item.price * quantity) }}</p>
            </div>
        </div>
    </div>
</template>