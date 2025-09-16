<script setup>
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import Badge from 'primevue/badge';

const props = defineProps({
    product: Object,
    visible: Boolean,
});

const emit = defineEmits(['update:visible', 'addToCart']);

const closeModal = () => {
    emit('update:visible', false);
};

const addProductToCart = () => {
    emit('addToCart', props.product);
    closeModal();
};

</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal :style="{ width: '450px' }" :header="false" :closable="false">
        <template #default>
            <div v-if="product" class="relative text-gray-800 dark:text-gray-200">
                <Button 
                    icon="pi pi-times" 
                    rounded text 
                    severity="secondary" 
                    @click="closeModal"
                    class="!absolute top-2 right-2 z-10 bg-white/70 dark:bg-black/70"
                />

                <div class="relative mb-4">
                    <img :src="product.image" :alt="product.name" class="w-full h-64 object-cover rounded-t-lg">
                    <Badge :value="`${product.stock} stock`" severity="contrast" class="absolute top-2 left-2"></Badge>
                </div>

                <div class="px-6 pb-6">
                    <h2 class="text-2xl font-bold mb-1">{{ product.name }}</h2>
                    <p class="text-md text-gray-500 dark:text-gray-400 mb-4">{{ product.category }}</p>

                    <div class="space-y-3 mb-4">
                        <div v-for="(options, variant) in product.variants" :key="variant">
                            <p class="text-sm font-semibold text-gray-600 dark:text-gray-300 mb-2 capitalize">{{ variant }}</p>
                            <div class="flex flex-wrap gap-2">
                                <div 
                                    v-for="option in options" 
                                    :key="option.value"
                                    v-tooltip.bottom="`Stock: ${option.stock}`"
                                    class="text-sm cursor-pointer px-3 py-1 rounded-md border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-gray-700 dark:text-gray-300 transition-colors hover:bg-gray-200 dark:hover:bg-gray-600">
                                    {{ option.value }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <p class="text-3xl font-bold text-gray-900 dark:text-gray-100 mb-4">${{ product.price.toFixed(2) }}</p>
                    
                    <div class="border-t border-gray-200 dark:border-gray-700 pt-4 space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-500 dark:text-gray-400">Código:</span>
                            <span class="font-medium">{{ product.code }}</span>
                        </div>
                         <div class="flex justify-between">
                            <span class="text-gray-500 dark:text-gray-400">Proveedor:</span>
                            <span class="font-medium">{{ product.provider || '-' }}</span>
                        </div>
                        <div v-if="product.description" class="pt-2">
                            <p class="text-gray-500 dark:text-gray-400 mb-1">Descripción:</p>
                            <div class="text-gray-600 dark:text-gray-300 list-disc list-inside" v-html="product.description"></div>
                        </div>
                    </div>
                </div>
                
                <div class="px-6 pb-6">
                    <Button label="Agregar al carrito" icon="pi pi-shopping-cart" severity="warning" class="w-full mt-4" @click="addProductToCart" />
                </div>
            </div>
        </template>
    </Dialog>
</template>