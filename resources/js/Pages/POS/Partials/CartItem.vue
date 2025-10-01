<script setup>
import { ref, watch } from 'vue';
import { usePermissions } from '@/Composables';

const props = defineProps({
    item: Object,
});

const emit = defineEmits(['updateQuantity', 'updatePrice', 'removeItem']);

// composables
const { hasPermission } = usePermissions();

const quantity = ref(props.item.quantity);
const price = ref(props.item.price);
const isEditingPrice = ref(false);

// Observa cambios en la cantidad local y notifica al padre
watch(quantity, (newQuantity) => {
    // Evita bucles infinitos asegurando que el valor realmente cambió
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

// --- SOLUCIÓN 2: Sincronizar el estado local con las props ---
// Si la prop de cantidad cambia desde el padre, actualizamos nuestro ref local.
watch(() => props.item.quantity, (newVal) => {
    if (quantity.value !== newVal) {
        quantity.value = newVal;
    }
});

// Hacemos lo mismo para el precio, por si se actualiza desde fuera.
watch(() => props.item.price, (newVal) => {
    if (price.value !== newVal) {
        price.value = newVal;
    }
});
</script>

<template>
    <div
        class="flex gap-4 relative bg-white dark:bg-gray-900 p-3 rounded-lg border border-gray-200 dark:border-gray-700">
        <!-- SOLUCIÓN 1: Enviar el identificador único del carrito (cartItemId) -->
        <Button @click="$emit('removeItem', item.cartItemId)" icon="pi pi-trash" rounded text severity="danger"
            size="small" class="absolute top-1 right-1" />
        <img :src="item.image" :alt="item.name" class="w-16 h-16 rounded-md object-cover">
        <div class="flex-grow">
            <p class="font-semibold text-sm leading-tight text-gray-800 dark:text-gray-200">{{ item.name }}</p>

            <!-- Precio (Editable) -->
            <div v-if="isEditingPrice" class="flex items-center gap-2">
                <InputText v-model.number="price" mode="decimal" :minFractionDigits="2" :maxFractionDigits="2"
                    class="p-inputtext-sm w-24" @keyup.enter="applyPriceChange" @keyup.esc="cancelPriceEdit" />
                <Button icon="pi pi-check" text rounded size="small" @click="applyPriceChange" />
                <Button icon="pi pi-times" text rounded size="small" severity="secondary" @click="cancelPriceEdit" />
            </div>
           <div v-else class="flex items-center gap-2">
                <p class="text-sm text-gray-600 dark:text-gray-400">${{ item.price.toFixed(2) }}</p>
                <!-- CAMBIO: Muestra el precio original tachado -->
                <del v-if="item.original_price && item.original_price > item.price" class="text-xs text-gray-400">${{ item.original_price.toFixed(2) }}</del>
                <Button v-if="hasPermission('pos.edit_prices')" @click="isEditingPrice = true" icon="pi pi-pencil" rounded text severity="secondary" style="width: 1.5rem; height: 1.5rem" />
            </div>

            <p class="text-xs text-gray-500"
                v-if="item.selectedVariant && Object.keys(item.selectedVariant).length > 0">
                <span v-for="(value, key, index) in item.selectedVariant" :key="key">
                    <span class="capitalize">{{ key }}</span>: {{ value }}{{ index <
                        Object.keys(item.selectedVariant).length - 1 ? ' / ' : '' }} </span>
            </p>

            <div class="flex justify-between items-center mt-1">
                <InputNumber v-model="quantity" showButtons buttonLayout="horizontal" :min="1"
                    decrementButtonClass="p-button-secondary" incrementButtonClass="p-button-secondary"
                    incrementButtonIcon="pi pi-plus" decrementButtonIcon="pi pi-minus"
                    :inputStyle="{ width: '3rem', textAlign: 'center' }" />
                <p class="font-bold text-gray-800 dark:text-gray-100">${{ (item.price * quantity).toFixed(2) }}</p>
            </div>
        </div>
    </div>
</template>