<script setup>
import { ref, watch, computed } from 'vue';
import { usePermissions } from '@/Composables';
import { FireIcon, StarIcon } from '@heroicons/vue/24/solid';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast'; // NUEVO: Importar Toast
import axios from 'axios'; // NUEVO: Importar axios

const props = defineProps({
    item: Object,
    appliedCartPromoNames: {
        type: Set,
        default: () => new Set(),
    }
});

const confirm = useConfirm();
const toast = useToast(); // NUEVO: Inicializar toast
const emit = defineEmits(['updateQuantity', 'updatePrice', 'removeItem']);
const { hasPermission } = usePermissions();

const quantity = ref(props.item.quantity);
const price = ref(props.item.price);
const isEditingPrice = ref(false);

// --- ESTADOS PARA MODAL DE PRECIO PERMANENTE ---
const isUpdatePriceModalVisible = ref(false);
const pendingPriceChange = ref(null);
const isUpdatingPricePermanent = ref(false);

// Observador para emitir cambios de cantidad
watch(quantity, (newQuantity) => {
    const validQuantity = Math.max(1, newQuantity || 1);
    if (validQuantity !== props.item.quantity) {
        emit('updateQuantity', { itemId: props.item.cartItemId, quantity: validQuantity });
    }
     if (newQuantity < 1 && quantity.value !== 1) {
       quantity.value = 1;
    }
});

// INTERCEPTAR EL CAMBIO DE PRECIO
const applyPriceChange = () => {
    const validPrice = Math.max(0, price.value || 0);
    if (validPrice !== props.item.price) {
        // En lugar de emitir directo, abrimos el modal
        pendingPriceChange.value = validPrice;
        isUpdatePriceModalVisible.value = true;
    } else {
         price.value = props.item.price;
         isEditingPrice.value = false;
    }
}

// Cancelar edición de precio (Botón "X" o cancelar modal)
const cancelPriceEdit = () => {
    price.value = props.item.price; 
    isEditingPrice.value = false;
    isUpdatePriceModalVisible.value = false;
    pendingPriceChange.value = null;
}

// Acción: Precio solo para esta venta
const confirmPriceForThisSaleOnly = () => {
    emit('updatePrice', { itemId: props.item.cartItemId, price: pendingPriceChange.value });
    isUpdatePriceModalVisible.value = false;
    isEditingPrice.value = false;
    pendingPriceChange.value = null;
};

// Acción: Precio permanente en catálogo
const confirmPricePermanent = async () => {
    isUpdatingPricePermanent.value = true;
    try {
        await axios.post(route('products.update-price-pos'), {
            product_id: props.item.id,
            product_attribute_id: props.item.product_attribute_id || null,
            new_price: pendingPriceChange.value
        });
        
        // Emite el cambio local al carrito
        emit('updatePrice', { itemId: props.item.cartItemId, price: pendingPriceChange.value });
        
        toast.add({ severity: 'success', summary: 'Catálogo actualizado', detail: 'El precio se ha modificado permanentemente en la base de datos.', life: 4000 });
    } catch (error) {
        console.error(error);
        toast.add({ severity: 'error', summary: 'Error al actualizar', detail: 'No se pudo guardar en el catálogo. Se aplicará solo a esta venta.', life: 4000 });
        // Fallback: Si falla, aplicarlo solo a la venta actual para no bloquear al cajero
        emit('updatePrice', { itemId: props.item.cartItemId, price: pendingPriceChange.value });
    } finally {
        isUpdatingPricePermanent.value = false;
        isUpdatePriceModalVisible.value = false;
        isEditingPrice.value = false;
        pendingPriceChange.value = null;
    }
};

watch(() => props.item.quantity, (newVal) => {
    if (quantity.value !== newVal) {
        quantity.value = newVal;
    }
});

watch(() => props.item.price, (newVal) => {
    if (!isEditingPrice.value && price.value !== newVal) {
        price.value = newVal;
    }
});

const isItemDiscountApplied = computed(() => props.item.original_price && props.item.price < props.item.original_price);
const isCartPromoAppliedToItem = computed(() => {
    if (!props.item.promotions || props.item.promotions.length === 0) return false;
    return props.item.promotions.some(p => props.appliedCartPromoNames.has(p.name));
});

const isPromoActive = computed(() => isItemDiscountApplied.value || isCartPromoAppliedToItem.value);
const isTierPriceActive = computed(() => props.item.isTierPrice === true);

const canEditPrice = computed(() => {
     if (hasPermission('pos.edit_prices')) return true;
     return isEditingPrice.value && props.item.isManualPrice; 
});

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN'
    }).format(value || 0);
};

const promoPopover = ref();
const togglePromoPopover = (event) => {
    promoPopover.value.toggle(event);
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
                 <StarIcon v-if="isTierPriceActive" class="size-4 text-amber-500 ml-1" v-tooltip.bottom="'Precio original de mayoreo'"/>
            </div>
            
            <!-- Visualización Normal de Precio -->
            <div v-else class="flex items-center gap-2 mt-1">
                 <p v-if="!isItemDiscountApplied && !isTierPriceActive" class="text-sm font-light text-[#373737] dark:text-gray-400 m-0">
                    {{ formatCurrency(item.price) }}
                 </p>
                 <div v-else class="flex items-center gap-2">
                     <del v-if="item.original_price && item.price < item.original_price" class="text-xs text-gray-400">{{ formatCurrency(item.original_price) }}</del>
                     <p class="text-sm font-bold text-[#373737] dark:text-gray-100 m-0">{{ formatCurrency(item.price) }}</p>
                     <StarIcon v-if="isTierPriceActive" class="size-4 text-amber-500" v-tooltip.bottom="'Precio de mayoreo aplicado'"/>
                 </div>
                 
                 <Button v-if="hasPermission('pos.edit_prices')" @click="isEditingPrice = true" icon="pi pi-pencil"
                    rounded variant="outlined" severity="secondary" class="!size-6" size="small"
                     v-tooltip.bottom="isTierPriceActive ? 'Editar precio (anula mayoreo)' : 'Editar precio'"
                     :disabled="isTierPriceActive && !props.item.isManualPrice" />
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

    <!-- Modales Globales del Componente -->
    <ConfirmPopup group="cart-item-delete" />

    <!-- NUEVO DIALOGO: ALCANCE DE EDICIÓN DE PRECIO -->
    <Dialog v-model:visible="isUpdatePriceModalVisible" modal header="Confirmar cambio de precio" :style="{ width: '25rem' }" @hide="cancelPriceEdit">
        <p class="text-gray-700 dark:text-gray-300 mb-5 text-sm">
            Has ingresado un nuevo precio de <strong class="text-primary-600 dark:text-primary-400 text-lg">{{ formatCurrency(pendingPriceChange) }}</strong>. <br><br>
            ¿Deseas aplicar este precio solo para esta venta o actualizar el catálogo de forma permanente?
        </p>
        <div class="flex flex-col gap-2">
            <Button label="Solo para esta venta" icon="pi pi-receipt" severity="primary" @click="confirmPriceForThisSaleOnly" />
            
            <Button 
                v-if="hasPermission('products.edit')" 
                label="Actualizar catálogo permanente" 
                icon="pi pi-database" 
                severity="info" 
                outlined 
                @click="confirmPricePermanent" 
                :loading="isUpdatingPricePermanent"
            />
            
            <Button label="Cancelar edición" icon="pi pi-times" severity="secondary" text @click="cancelPriceEdit" />
        </div>
        <p v-if="!hasPermission('products.edit')" class="text-xs text-gray-500 text-center mt-3 mb-0">No tienes permisos de administrador para actualizar el catálogo.</p>
    </Dialog>
</template>