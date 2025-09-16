<script setup>
import { ref } from 'vue';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Avatar from 'primevue/avatar';

import CartItem from './CartItem.vue';

const props = defineProps({
    items: Array,
    client: Object,
});

const selectedClient = ref(props.client);

</script>

<template>
    <div class="bg-white p-6 rounded-lg shadow-md h-full flex flex-col">
        <!-- Header del Carrito -->
        <div class="flex justify-between items-center pb-4 border-b">
            <h2 class="text-xl font-bold">Carrito</h2>
            <div class="flex items-center gap-2">
                <Button icon="pi pi-save" rounded text severity="secondary" aria-label="Guardar Carrito" v-tooltip.bottom="'Guardar para después'"/>
                <Button icon="pi pi-trash" rounded text severity="danger" aria-label="Limpiar Carrito" v-tooltip.bottom="'Limpiar carrito'"/>
            </div>
        </div>

        <!-- Selector de Cliente -->
        <div class="py-4 border-b">
            <div class="flex items-center gap-2 mb-3">
                 <InputText placeholder="Buscar cliente por nombre o teléfono" class="w-full"/>
                 <Button icon="pi pi-plus" severity="secondary" aria-label="Agregar Cliente"/>
            </div>
            <div v-if="selectedClient" class="flex items-center justify-between bg-gray-50 p-2 rounded-lg">
                <div class="flex items-center gap-3">
                    <Avatar :label="selectedClient.initials" shape="circle" class="bg-blue-100 text-blue-600"/>
                    <div>
                        <p class="font-semibold text-sm">{{ selectedClient.name }}</p>
                        <p class="text-xs text-gray-500">{{ selectedClient.phone }}</p>
                    </div>
                </div>
                <Button @click="selectedClient = null" icon="pi pi-times" rounded text severity="secondary" size="small"/>
            </div>
        </div>

        <!-- Lista de Items en el Carrito -->
        <div class="flex-grow py-4 overflow-y-auto space-y-4">
            <p v-if="items.length === 0" class="text-gray-500 text-center mt-8">El carrito está vacío</p>
            <CartItem v-for="item in items" :key="item.id" :item="item" />
        </div>

        <!-- Detalles del Pago -->
        <div class="pt-4 border-t space-y-3">
            <div class="flex justify-between items-center text-gray-600">
                <span>Subtotal</span>
                <span class="font-medium">$290.00</span>
            </div>
             <div class="flex justify-between items-center text-red-500">
                <span>Descuento</span>
                <span class="font-medium">-$80.00</span>
            </div>
             <div class="flex justify-between items-center font-bold text-lg">
                <span>Total</span>
                <span>$210.00</span>
            </div>
            <Button label="Pagar" icon="pi pi-arrow-right" iconPos="right" class="w-full mt-2 bg-orange-500 hover:bg-orange-600 border-none"/>
        </div>
    </div>
</template>