<script setup>
import Button from 'primevue/button';
import InputNumber from 'primevue/inputnumber';
import { ref } from 'vue';

const props = defineProps({
    item: Object,
});

const quantity = ref(props.item.quantity);

</script>

<template>
    <div class="flex gap-4 relative bg-white p-3 rounded-lg border">
         <Button icon="pi pi-trash" rounded text severity="danger" size="small" class="absolute top-1 right-1"/>
        <img :src="item.image" :alt="item.name" class="w-16 h-16 rounded-md object-cover">
        <div class="flex-grow">
            <p class="font-semibold text-sm leading-tight">{{ item.name }}</p>
            <div class="flex items-center gap-2">
                <p class="text-sm text-gray-600">${{ item.price.toFixed(2) }}</p>
                <Button icon="pi pi-pencil" rounded text severity="secondary" style="width: 1.5rem; height: 1.5rem" />
            </div>
            <p class="text-xs text-gray-500" v-if="Object.keys(item.variants).length > 0">
               <span v-for="(value, key, index) in item.variants" :key="key">
                   {{ key }}: {{ value }}{{ index < Object.keys(item.variants).length - 1 ? ' / ' : '' }}
               </span>
            </p>
            <div class="flex justify-between items-center mt-1">
                <InputNumber v-model="quantity" showButtons buttonLayout="horizontal" :min="1"
                    decrementButtonClass="p-button-secondary" incrementButtonClass="p-button-secondary"
                    incrementButtonIcon="pi pi-plus" decrementButtonIcon="pi pi-minus" 
                    :inputStyle="{width: '3rem', textAlign: 'center'}" />
                <p class="font-bold">${{ (item.price * quantity).toFixed(2) }}</p>
            </div>
        </div>
    </div>
</template>