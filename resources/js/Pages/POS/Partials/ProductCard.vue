<script setup>
import Button from 'primevue/button';
import Badge from 'primevue/badge';

const props = defineProps({
    product: Object,
});

const emit = defineEmits(['showDetails', 'addToCart']);

const handlePrimaryAction = () => {
    if (props.product.variants && Object.keys(props.product.variants).length > 0) {
        emit('showDetails', props.product);
    } else {
        emit('addToCart', { product: props.product });
    }
};
</script>

<template>
    <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden flex flex-col bg-white dark:bg-gray-800 transition-shadow hover:shadow-lg">
        <div class="relative">
            <img :src="product.image" :alt="product.name" class="w-full h-40 object-cover">
            <!-- CAMBIO: Muestra el badge de promoción si existe -->
            <Badge v-if="product.promotion" value="Oferta" severity="danger" class="absolute top-2 right-2"></Badge>
            <Badge :value="`${product.stock} en stock`" severity="contrast" class="absolute top-2 left-2"></Badge>
            <Button icon="pi pi-arrows-alt" rounded text severity="secondary" class="absolute top-1 right-1 bg-white/50 dark:bg-black/50" @click="emit('showDetails', product)" v-tooltip.bottom="'Ver detalles'"/>
        </div>
        <div class="p-4 flex flex-col flex-grow">
            <h3 class="font-bold text-gray-800 dark:text-gray-200 h-12">{{ product.name }}</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">{{ product.category }}</p>

            <div class="space-y-2 mt-auto mb-3 min-h-[3rem]">
                 <div v-for="(options, variantName) in product.variants" :key="variantName" class="flex items-center gap-2">
                     <span class="text-xs font-bold w-12 capitalize">{{ variantName }}:</span>
                     <div class="flex flex-wrap gap-1">
                         <div v-for="option in options.slice(0, 3)" :key="option.value" v-tooltip.bottom="`Stock: ${option.stock}`" class="text-xs px-2 py-1 rounded-md bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">{{ option.value }}</div>
                         <div v-if="options.length > 3" class="text-xs px-2 py-1">...</div>
                     </div>
                </div>
            </div>

            <!-- CAMBIO: Muestra precio original y de oferta -->
            <div class="mb-3">
                <div v-if="product.promotion" class="flex items-baseline gap-2">
                    <p class="text-xl font-semibold text-red-600">${{ product.price.toFixed(2) }}</p>
                    <del class="text-md text-gray-500">${{ product.promotion.original_price.toFixed(2) }}</del>
                </div>
                <p v-else class="text-xl font-semibold text-gray-900 dark:text-gray-100">${{ product.price.toFixed(2) }}</p>
            </div>
            
            <Button :label="product.variants && Object.keys(product.variants).length > 0 ? 'Seleccionar variante' : 'Agregar al carrito'" icon="pi pi-plus" severity="warning" class="w-full mt-auto" @click="handlePrimaryAction"/>
        </div>
    </div>
</template>