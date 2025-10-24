<script setup>
import { ref, watch, computed } from 'vue';
import { usePermissions } from '@/Composables';
import { FireIcon, StarIcon } from '@heroicons/vue/24/solid'; // <-- Importar StarIcon
import { useConfirm } from 'primevue/useconfirm';

const props = defineProps({
    item: Object,
    appliedCartPromoNames: {
        type: Set,
        default: () => new Set(),
    }
});

const confirm = useConfirm();

const emit = defineEmits(['updateQuantity', 'updatePrice', 'removeItem']);

const { hasPermission } = usePermissions();

const quantity = ref(props.item.quantity);
const price = ref(props.item.price);
const isEditingPrice = ref(false);

// Observador para emitir cambios de cantidad
watch(quantity, (newQuantity) => {
    // Validar que la cantidad sea al menos 1
    const validQuantity = Math.max(1, newQuantity || 1);
    if (validQuantity !== props.item.quantity) {
        emit('updateQuantity', { itemId: props.item.cartItemId, quantity: validQuantity });
    }
     // Si el usuario introduce 0 o menos, resetear a 1 internamente
     if (newQuantity < 1 && quantity.value !== 1) {
       quantity.value = 1;
    }
});


// Aplicar cambio de precio manual
const applyPriceChange = () => {
    // Validar que el precio sea positivo
    const validPrice = Math.max(0, price.value || 0);
    if (validPrice !== props.item.price) {
        emit('updatePrice', { itemId: props.item.cartItemId, price: validPrice });
    } else {
         // Si el precio validado es el mismo que el actual, solo cierra la edición
         price.value = props.item.price; // Asegura que el ref interno tenga el valor correcto
    }
    isEditingPrice.value = false;
}

// Cancelar edición de precio
const cancelPriceEdit = () => {
    price.value = props.item.price; // Revertir al precio actual del item
    isEditingPrice.value = false;
}

// Observador para actualizar cantidad si cambia externamente (ej. al resumir carrito)
watch(() => props.item.quantity, (newVal) => {
    if (quantity.value !== newVal) {
        quantity.value = newVal;
    }
});

// Observador para actualizar precio si cambia externamente (ej. por cambio de cantidad)
watch(() => props.item.price, (newVal) => {
    // Solo actualizar si no estamos editando, para evitar sobrescribir
    if (!isEditingPrice.value && price.value !== newVal) {
        price.value = newVal;
    }
});

// Check si hay un descuento aplicado directamente al item (promo directa o manual)
const isItemDiscountApplied = computed(() => props.item.original_price && props.item.price < props.item.original_price);

// Check si una promo de carrito afecta a este item (visualmente)
const isCartPromoAppliedToItem = computed(() => {
    if (!props.item.promotions || props.item.promotions.length === 0) {
        return false;
    }
    // Verifica si alguna promoción del item está en el set de promociones aplicadas del carrito
    return props.item.promotions.some(p => props.appliedCartPromoNames.has(p.name));
});

// Determina si mostrar el icono de promoción activo
const isPromoActive = computed(() => isItemDiscountApplied.value || isCartPromoAppliedToItem.value);

// Check si se está aplicando un precio de mayoreo
const isTierPriceActive = computed(() => props.item.isTierPrice === true);

// Determina si el usuario puede editar el precio
const canEditPrice = computed(() => {
     // Si tiene el permiso general, siempre puede editar
     if (hasPermission('pos.edit_prices')) {
        return true;
     }
     // Si no tiene permiso general:
     // - No puede editar si es precio de mayoreo
     // - Sí puede revertir un precio manual (isEditingPrice sería true)
     // - No puede editar un precio normal si no tiene permiso
    return isEditingPrice.value && props.item.isManualPrice; // Permitir cancelar/guardar si ya está editando un precio manual

});

// Formateador de moneda
const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN'
    }).format(value || 0);
};

// --- Lógica Popover Promociones ---
const promoPopover = ref();
const togglePromoPopover = (event) => {
    promoPopover.value.toggle(event);
};

// Función resumen promoción (sin cambios respecto a la versión anterior)
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
             // Simplificado para el popover
            return `Compra ${rule.value} y llévate ${effect.value} gratis.`;
        }
        case 'BUNDLE_PRICE': {
            const effect = promo.effects.find(e => e.type === 'SET_PRICE');
             if (!effect || promo.rules.length === 0) return promo.description || 'Promoción de paquete.';
             // Simplificado para el popover
            const productCount = promo.rules.filter(r => r.type === 'REQUIRES_PRODUCT' && r.itemable).length;
            return `Paquete (${productCount} prod.) por ${formatCurrency(effect.value)}.`;
        }
        default:
            return promo.description || 'Promoción especial.';
    }
};

const confirmRemoveItem = (event, itemId) => {
    confirm.require({
        target: event.currentTarget,
        message: '¿Estás seguro de que quieres eliminar este elemento?',
        group: 'cart-item-delete',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Sí',
        rejectLabel: 'No',
        accept: () => {
           emit('removeItem', itemId)
        }
    });
};
</script>

<template>
    <div
        class="flex gap-4 relative bg-white dark:bg-gray-900 p-3 rounded-xl border border-[#D9D9D9] dark:border-gray-700">
        <!-- Imagen del Producto -->
        <img :src="item.image" :alt="item.name" class="size-16 rounded-[10px] object-contain bg-[#f2f2f2]">

        <!-- Detalles del Producto y Controles -->
        <div class="flex-grow">
            <!-- Nombre -->
            <p class="font-bold text-sm leading-tight text-[#373737] dark:text-gray-200 w-[87%]">{{ item.name }}</p>

            <!-- Input de Edición de Precio -->
            <div v-if="isEditingPrice" class="flex items-center gap-1 mt-1">
                <InputNumber fluid v-model.number="price" mode="currency" currency="MXN"
                    locale="es-MX"
                    class="!w-24 !h-[2rem]" @keyup.enter="applyPriceChange" @keyup.esc="cancelPriceEdit" />
                <Button icon="pi pi-check" variant="outlined" rounded size="small" @click="applyPriceChange" class="!size-6" />
                <Button icon="pi pi-times" variant="outlined" rounded size="small" severity="secondary" @click="cancelPriceEdit" class="!size-6" />
                 <!-- Indicador si el precio original era de mayoreo -->
                 <StarIcon v-if="isTierPriceActive" class="size-4 text-amber-500 ml-1" v-tooltip.bottom="'Precio original de mayoreo'"/>
            </div>
            <!-- Visualización Normal de Precio -->
            <div v-else class="flex items-center gap-2 mt-1">
                 <!-- Precio normal (sin descuento ni mayoreo) -->
                 <p v-if="!isItemDiscountApplied && !isTierPriceActive" class="text-sm font-light text-[#373737] dark:text-gray-400 m-0">
                    {{ formatCurrency(item.price) }}
                 </p>
                 <!-- Precio con descuento o mayoreo -->
                 <div v-else class="flex items-center gap-2">
                     <!-- Mostrar Original Tachado si hay descuento O es precio de mayoreo y es menor al original -->
                     <del v-if="item.original_price && item.price < item.original_price" class="text-xs text-gray-400">{{ formatCurrency(item.original_price) }}</del>
                     <!-- Precio Actual -->
                     <p class="text-sm font-bold text-[#373737] dark:text-gray-100 m-0">{{ formatCurrency(item.price) }}</p>
                     <!-- Icono de Mayoreo -->
                     <StarIcon v-if="isTierPriceActive" class="size-4 text-amber-500" v-tooltip.bottom="'Precio de mayoreo aplicado'"/>
                 </div>
                 <!-- Botón Editar (ahora con lógica compleja de :disabled) -->
                 <Button v-if="hasPermission('pos.edit_prices')" @click="isEditingPrice = true" icon="pi pi-pencil"
                    rounded variant="outlined" severity="secondary" class="!size-6" size="small"
                     v-tooltip.bottom="isTierPriceActive ? 'Editar precio (anula mayoreo)' : 'Editar precio'"
                     :disabled="isTierPriceActive && !props.item.isManualPrice" /> <!-- Deshabilitar si es tier y no manual -->
                 <span v-else-if="!hasPermission('pos.edit_prices') && isTierPriceActive" v-tooltip.bottom="'Edición deshabilitada para precios de mayoreo'">
                      <Button icon="pi pi-pencil" rounded variant="outlined" severity="secondary" class="!size-6 opacity-50" size="small" disabled />
                 </span>
            </div>

            <!-- Mostrar Variantes Seleccionadas -->
            <p class="text-xs text-gray-500"
                v-if="item.selectedVariant && Object.keys(item.selectedVariant).length > 0">
                <span v-for="(value, key, index) in item.selectedVariant" :key="key">
                    <span class="capitalize">{{ key }}</span>: {{ value }}{{ index <
                        Object.keys(item.selectedVariant).length - 1 ? ' / ' : '' }} </span>
            </p>

            <!-- Controles de Cantidad y Total -->
            <div class="flex justify-between items-end mt-2">
                <!-- Input de Cantidad -->
                <InputNumber v-model="quantity" showButtons buttonLayout="horizontal" :min="1"
                    decrementButtonClass="p-button-secondary !py-1 !px-2" incrementButtonClass="p-button-secondary !py-1 !px-2" incrementButtonIcon="pi pi-plus" decrementButtonIcon="pi pi-minus"
                    :inputStyle="{ width: '3rem', height: '2rem', textAlign: 'center' }" size="small" />

                <!-- Icono Promociones y Total de Línea -->
                <div class="flex items-center gap-1">
                    <!-- Popover de Promociones -->
                     <div v-if="item.promotions && item.promotions.length > 0">
                        <button @click="togglePromoPopover($event)" v-tooltip.bottom="'Ver promociones disponibles'">
                            <FireIcon class="size-5"
                                :class="isPromoActive ? 'text-[#AE080B] dark:text-red-400 animate-pulse' : 'text-gray-400 dark:text-gray-600'" />
                        </button>
                        <Popover ref="promoPopover">
                            <div class="p-3 w-60">
                                <h4 class="font-bold text-base mb-2 border-b pb-2 dark:border-gray-700">Promociones disponibles</h4>
                                <div class="space-y-3 max-h-48 overflow-y-auto text-gray-800 dark:text-gray-200">
                                    <div v-for="promo in item.promotions" :key="promo.name" class="text-sm">
                                        <p class="font-semibold m-0">{{ promo.name }}</p>
                                        <p class="text-xs text-gray-600 dark:text-gray-400 m-0">{{ getPromotionSummary(promo) }}</p>
                                    </div>
                                </div>
                            </div>
                        </Popover>
                    </div>
                     <!-- Total de Línea -->
                    <p class="font-bold text-gray-800 dark:text-gray-100 m-0">
                        {{ formatCurrency(item.price * quantity) }}
                    </p>
                </div>
            </div>
        </div>
        <!-- Botón Eliminar -->
        <Button @click="confirmRemoveItem($event, item.cartItemId)" icon="pi pi-trash" rounded variant="outlined" severity="danger"
            size="small" class="!size-7 !absolute top-1 right-1" />
    </div>
    <ConfirmPopup group="cart-item-delete" />
</template>