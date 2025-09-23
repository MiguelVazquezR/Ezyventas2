<script setup>
const props = defineProps({
    product: Object,
});

const emit = defineEmits(['showDetails', 'addToCart']);

</script>

<template>
    <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden flex flex-col bg-white dark:bg-gray-800 transition-shadow hover:shadow-lg">
        <div class="relative">
            <img :src="product.image" :alt="product.name" class="w-full h-40 object-cover">
            <Badge :value="`${product.stock} stock`" severity="contrast" class="absolute top-2 left-2"></Badge>
             <!-- Botón para abrir el modal de detalles -->
             <Button 
                icon="pi pi-arrows-alt" 
                rounded text 
                severity="secondary" 
                class="absolute top-1 right-1 bg-white/50 dark:bg-black/50"
                @click="emit('showDetails', product)"
                v-tooltip.bottom="'Ver detalles'"
            />
        </div>
        <div class="p-4 flex flex-col flex-grow">
            <h3 class="font-bold text-gray-800 dark:text-gray-200">{{ product.name }}</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">{{ product.category }}</p>

            <!-- Las variantes ahora son más simples en la tarjeta -->
            <div class="space-y-2 mt-auto mb-3">
                <div v-for="(options, variant) in product.variants" :key="variant">
                     <div class="flex flex-wrap gap-1">
                         <div v-for="option in options.slice(0, 4)" :key="option.value"
                            class="text-xs px-2 py-1 rounded-md bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                            {{ option.value }}
                        </div>
                        <div v-if="options.length > 4" class="text-xs px-2 py-1">...</div>
                    </div>
                </div>
            </div>

            <p class="text-xl font-semibold text-gray-900 dark:text-gray-100 mb-3">${{ product.price.toFixed(2) }}</p>
            <Button 
                label="Agregar al carrito" 
                icon="pi pi-plus" 
                severity="warning" 
                class="w-full mt-auto"
                @click="emit('addToCart', product)"
            />
        </div>
    </div>
</template>

