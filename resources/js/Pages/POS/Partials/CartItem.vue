<script setup>
import { ref, watch, computed } from 'vue';
import { usePermissions } from '@/Composables';
import { FireIcon, StarIcon } from '@heroicons/vue/24/solid';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast'; 
import axios from 'axios'; 

const props = defineProps({
    item: Object,
    appliedCartPromoNames: {
        type: Set,
        default: () => new Set(),
    }
});

const confirm = useConfirm();
const toast = useToast(); 
const emit = defineEmits(['updateQuantity', 'updatePrice', 'removeItem']);
const { hasPermission } = usePermissions();

const quantity = ref(props.item.quantity);
const price = ref(props.item.price);
const isEditingPrice = ref(false);

// --- ESTADOS PARA MODAL DE PRECIO PERMANENTE ---
const isUpdatePriceModalVisible = ref(false);
const pendingPriceChange = ref(null);
const isUpdatingPricePermanent = ref(false);

// Observador para emitir cambios de cantidad (Soporte para Venta a Granel)
watch(quantity, (newQuantity) => {
    // Si es a granel, el mínimo permitido es 0.001, si no, es 1.
    const minQty = props.item.is_bulk ? 0.001 : 1;
    const validQuantity = Math.max(minQty, Number(newQuantity) || minQty);
    
    if (validQuantity !== props.item.quantity) {
        emit('updateQuantity', { itemId: props.item.cartItemId, quantity: validQuantity });
    }
    
    if (newQuantity < minQty && quantity.value !== minQty) {
        quantity.value = minQty;
    }
});

// INTERCEPTAR EL CAMBIO DE PRECIO
const applyPriceChange = () => {
    const validPrice = Math.max(0, price.value || 0);
    if (validPrice !== props.item.price) {
        pendingPriceChange.value = validPrice;
        isUpdatePriceModalVisible.value = true;
    } else {
         price.value = props.item.price;
         isEditingPrice.value = false;
    }
}

const cancelPriceEdit = () => {
    price.value = props.item.price; 
    isEditingPrice.value = false;
    isUpdatePriceModalVisible.value = false;
    pendingPriceChange.value = null;
}

const confirmPriceForThisSaleOnly = () => {
    emit('updatePrice', { itemId: props.item.cartItemId, price: pendingPriceChange.value });
    isUpdatePriceModalVisible.value = false;
    isEditingPrice.value = false;
    pendingPriceChange.value = null;
};

const confirmPricePermanent = async () => {
    isUpdatingPricePermanent.value = true;
    try {
        await axios.post(route('products.update-price-pos'), {
            product_id: props.item.id,
            product_attribute_id: props.item.product_attribute_id || null,
            new_price: pendingPriceChange.value
        });
        
        emit('updatePrice', { itemId: props.item.cartItemId, price: pendingPriceChange.value });
        
        toast.add({ severity: 'success', summary: 'Catálogo actualizado', detail: 'El precio se ha modificado permanentemente en la base de datos.', life: 4000 });
    } catch (error) {
        console.error(error);
        toast.add({ severity: 'error', summary: 'Error al actualizar', detail: 'No se pudo guardar en el catálogo. Se aplicará solo a esta venta.', life: 4000 });
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
        message: '¿Estás seguro de que quieres eliminar este artículo?',
        group: 'cart-item-delete',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'Cancelar',
        accept: () => {
           emit('removeItem', itemId)
        }
    });
};
</script>

<template>
    <div class="flex gap-3 relative bg-gray-50 dark:bg-[#1a1a1a] p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] transition-all group hover:border-gray-200 dark:hover:border-gray-600">
        <!-- Imagen del Producto -->
        <div class="w-14 h-14 rounded-xl bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#2a2a2a] p-1 flex items-center justify-center flex-shrink-0">
            <img :src="item.image" :alt="item.name" class="w-full h-full object-contain drop-shadow-sm">
        </div>

        <!-- Detalles del Producto y Controles -->
        <div class="flex-grow flex flex-col justify-between">
            
            <div class="pr-6">
                <!-- Nombre -->
                <p class="font-medium text-[13px] leading-tight text-gray-900 dark:text-white m-0 line-clamp-2" :title="item.name">
                    {{ item.name }}
                </p>

                <!-- Mostrar Variantes Seleccionadas (Oculto si no tiene) -->
                <p class="text-[9px] uppercase tracking-widest text-gray-400 dark:text-gray-500 m-0 mt-0.5 truncate"
                    v-if="item.selectedVariant && Object.keys(item.selectedVariant).length > 0">
                    <span v-for="(value, key, index) in item.selectedVariant" :key="key">
                        <span class="font-bold">{{ key }}</span>: {{ value }}{{ index <
                            Object.keys(item.selectedVariant).length - 1 ? ' / ' : '' }} </span>
                </p>

                <!-- Input de Edición de Precio -->
                <div v-if="isEditingPrice" class="flex items-center gap-1 mt-1.5">
                    <InputNumber fluid v-model.number="price" mode="currency" currency="MXN"
                        locale="es-MX"
                        :pt="{ input: { root: { class: '!w-24 !h-7 !text-xs dark:!bg-[#232323] dark:!border-[#3a3a3a] dark:!text-white' } } }"
                        @keyup.enter="applyPriceChange" @keyup.esc="cancelPriceEdit" />
                    <Button icon="pi pi-check" rounded @click="applyPriceChange" size="small" class="!bg-green-500 !border-none !text-white" />
                    <Button icon="pi pi-times" rounded severity="secondary" @click="cancelPriceEdit" size="small" class="!bg-gray-200 dark:!bg-[#3a3a3a] !border-none !text-gray-600 dark:!text-gray-300" />
                    <StarIcon v-if="isTierPriceActive" class="size-3.5 text-amber-500 ml-1" v-tooltip.bottom="'Precio original de mayoreo'"/>
                </div>
                
                <!-- Visualización Normal de Precio Unitario -->
                <div v-else class="flex items-center gap-2 mt-1">
                     <p v-if="!isItemDiscountApplied && !isTierPriceActive" class="font-mono text-[11px] text-gray-600 dark:text-gray-400 m-0">
                        {{ formatCurrency(item.price) }}
                        <!-- Texto de unidad si es a granel -->
                        <span v-if="item.is_bulk" class="text-[9px] text-gray-400 ml-0.5">/ {{ item.measure_unit || 'Ud' }}</span>
                     </p>
                     <div v-else class="flex items-center gap-1.5">
                         <del v-if="item.original_price && item.price < item.original_price" class="text-[9px] text-gray-400">{{ formatCurrency(item.original_price) }}</del>
                         <p class="font-mono text-[11px] font-bold text-gray-900 dark:text-gray-200 m-0">
                            {{ formatCurrency(item.price) }}
                            <span v-if="item.is_bulk" class="text-[9px] font-normal text-gray-400 ml-0.5">/ {{ item.measure_unit || 'Ud' }}</span>
                         </p>
                         <StarIcon v-if="isTierPriceActive" class="size-3.5 text-amber-500" v-tooltip.bottom="'Precio de mayoreo aplicado'"/>
                     </div>
                     
                     <button v-if="hasPermission('pos.edit_prices')" @click="isEditingPrice = true"
                         class="text-gray-400 hover:text-primary-500 transition-colors bg-transparent border-none p-0 cursor-pointer flex items-center justify-center disabled:opacity-30 disabled:cursor-not-allowed"
                         v-tooltip.bottom="isTierPriceActive ? 'Editar precio (anula mayoreo)' : 'Editar precio unitario'"
                         :disabled="isTierPriceActive && !props.item.isManualPrice">
                         <i class="pi pi-pencil !text-[9px]"></i>
                     </button>
                </div>
            </div>

            <!-- Controles de Cantidad y Total -->
            <div class="flex justify-between items-end mt-2">
                <!-- Input de Cantidad (Estilo Integrado) -->
                <div class="flex items-center gap-1.5 flex-shrink-0">
                    <InputNumber v-model="quantity" fluid showButtons buttonLayout="horizontal" 
                        :min="item.is_bulk ? 0.001 : 1"
                        :maxFractionDigits="item.is_bulk ? 3 : 0"
                        :step="item.is_bulk ? 0.25 : 1"
                        incrementButtonIcon="pi pi-plus !text-[10px]" 
                        decrementButtonIcon="pi pi-minus !text-[10px]"
                        :pt="{
                            root: { class: 'h-7 max-w-[9.5rem] flex-shrink-0' },
                            input: { root: { class: 'min-w-0 !w-8 md:!w-10 !h-7 !text-center !text-[10px] !font-bold dark:!bg-[#232323] dark:!border-[#3a3a3a] dark:!text-white !p-0 shadow-inner' } },
                            incrementButton: { root: { class: '!w-7 !h-7 !px-0 !rounded-r-md dark:!bg-[#2a2a2a] dark:!border-[#3a3a3a] dark:!text-gray-300 hover:dark:!bg-primary-500 hover:dark:!border-primary-500 hover:!text-white transition-colors' } },
                            decrementButton: { root: { class: '!w-7 !h-7 !px-0 !rounded-l-md dark:!bg-[#2a2a2a] dark:!border-[#3a3a3a] dark:!text-gray-300 hover:dark:!bg-primary-500 hover:dark:!border-primary-500 hover:!text-white transition-colors' } }
                        }"
                    />
                    <span v-if="item.is_bulk" class="text-[9px] font-bold text-gray-500 uppercase tracking-widest">
                        {{ item.measure_unit || 'UD' }}
                    </span>
                </div>

                <!-- Total de Línea y Promociones -->
                <div class="flex items-center gap-2">
                     <div v-if="item.promotions && item.promotions.length > 0">
                        <button @click="togglePromoPopover($event)" class="bg-transparent border-none p-0 cursor-pointer flex items-center justify-center" v-tooltip.bottom="'Ver promociones activas'">
                            <FireIcon class="size-4"
                                :class="isPromoActive ? 'text-red-500 animate-pulse' : 'text-gray-400 dark:text-gray-600'" />
                        </button>
                        <Popover ref="promoPopover" :pt="{ root: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-2xl shadow-xl' } }">
                            <div class="p-4 w-60">
                                <h4 class="font-medium text-sm mb-3 border-b border-gray-100 dark:border-[#3a3a3a] pb-2 m-0 text-gray-900 dark:text-white tracking-tight">Promociones disponibles</h4>
                                <div class="space-y-3 max-h-48 overflow-y-auto custom-scrollbar pr-1">
                                    <div v-for="promo in item.promotions" :key="promo.name">
                                        <p class="text-[10px] font-bold uppercase tracking-widest text-gray-800 dark:text-gray-200 m-0 mb-1">{{ promo.name }}</p>
                                        <p class="text-[11px] text-gray-600 dark:text-gray-400 m-0 leading-tight">{{ getPromotionSummary(promo) }}</p>
                                    </div>
                                </div>
                            </div>
                        </Popover>
                    </div>
                    <!-- Total en la partida -->
                    <p class="font-light tracking-tight text-lg text-gray-900 dark:text-white m-0 leading-none">
                        {{ formatCurrency(item.price * quantity) }}
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Botón Eliminar (Integrado y discreto) -->
        <button @click="confirmRemoveItem($event, item.cartItemId)" 
            class="absolute top-2 right-2 w-7 h-7 rounded-full bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 hover:border-red-200 dark:hover:border-red-900/50 transition-all duration-300 flex items-center justify-center shadow-sm"
            aria-label="Eliminar artículo" v-tooltip.left="'Quitar'">
            <i class="pi pi-times !text-[10px]"></i>
        </button>
    </div>

    <!-- Modales Globales del Componente -->
    <ConfirmPopup group="cart-item-delete" :pt="{ root: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-2xl' } }" />

    <!-- DIALOGO: ALCANCE DE EDICIÓN DE PRECIO -->
    <Dialog v-model:visible="isUpdatePriceModalVisible" modal header="Cambio de precio" 
        class="w-full max-w-sm"
        @hide="cancelPriceEdit"
        :pt="{
            root: { class: 'dark:bg-[#232323] border-none shadow-2xl rounded-3xl overflow-hidden' },
            header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-5' },
            title: { class: 'text-xl font-light tracking-tight text-gray-900 dark:text-white m-0' },
            content: { class: 'dark:bg-[#232323] px-6 py-5' }
        }">
        
        <div class="mb-6 bg-blue-50 dark:bg-blue-900/10 p-4 rounded-2xl border border-blue-100 dark:border-blue-900/30">
            <p class="text-sm text-blue-800 dark:text-blue-200 m-0 leading-relaxed text-center">
                Nuevo precio: <strong class="text-2xl font-light block mt-1">{{ formatCurrency(pendingPriceChange) }}</strong>
            </p>
        </div>
        
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-6 text-center">
            ¿Deseas aplicar este precio solo para esta venta o actualizar el catálogo de forma permanente?
        </p>

        <div class="flex flex-col gap-3">
            <Button label="Solo para esta venta" icon="pi pi-receipt" severity="primary" @click="confirmPriceForThisSaleOnly" class="!rounded-xl !uppercase !tracking-widest !text-[10px] !font-bold !py-3" />
            
            <Button 
                v-if="hasPermission('products.edit')" 
                label="Actualizar catálogo permanente" 
                icon="pi pi-database" 
                severity="secondary" 
                @click="confirmPricePermanent" 
                :loading="isUpdatingPricePermanent"
                class="!rounded-xl !uppercase !tracking-widest !text-[10px] !font-bold !py-3 !bg-gray-100 dark:!bg-[#1a1a1a] !text-gray-700 dark:!text-gray-300 !border-gray-200 dark:!border-[#3a3a3a]"
            />
            
            <Button label="Cancelar" severity="secondary" text @click="cancelPriceEdit" class="!rounded-xl !uppercase !tracking-widest !text-[10px] !font-bold" />
        </div>

        <p v-if="!hasPermission('products.edit')" class="text-[10px] uppercase tracking-widest text-orange-500 text-center mt-4 mb-0 flex items-center justify-center gap-1">
            <i class="pi pi-lock !text-[9px]"></i> Sin permisos de edición maestra
        </p>
    </Dialog>
</template>