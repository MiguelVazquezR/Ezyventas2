<script setup>
import { ref } from 'vue';
import Button from 'primevue/button';
import Badge from 'primevue/badge';
import Popover from 'primevue/popover';

const props = defineProps({
    product: Object,
});

const emit = defineEmits(['showDetails', 'addToCart']);

const promoPopover = ref();
const togglePromoPopover = (event) => {
    promoPopover.value.toggle(event);
};

const handlePrimaryAction = () => {
    if (props.product.variants && Object.keys(props.product.variants).length > 0) {
        emit('showDetails', props.product);
    } else {
        emit('addToCart', { product: props.product });
    }
};

const getPromotionSummary = (promo) => {
    const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);

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
            return `Compra ${rule.value} de "${rule.itemable.name}" y llévate ${effect.value} de "${effect.itemable.name}" gratis.`;
        }
        case 'BUNDLE_PRICE': {
            const effect = promo.effects.find(e => e.type === 'SET_PRICE');
            if (!effect || promo.rules.length === 0) return promo.description || 'Promoción de paquete.';
            const productNames = promo.rules.filter(r => r.type === 'REQUIRES_PRODUCT' && r.itemable).map(r => r.itemable.name).join(' + ');
            return `Paquete (${productNames}) por ${formatCurrency(effect.value)}.`;
        }
        default:
            return promo.description || 'Promoción especial.';
    }
};
</script>

<template>
    <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden flex flex-col bg-white dark:bg-gray-800 transition-shadow hover:shadow-lg">
        <div class="relative">
            <img :src="product.image" :alt="product.name" class="w-full h-40 object-cover">
            
            <div v-if="product.promotions && product.promotions.length > 0"
                 @click="togglePromoPopover"
                 class="absolute top-2 right-2 cursor-pointer"
                 v-tooltip.bottom="'Ver detalles de la promoción'">
                <Badge value="Promoción" severity="danger"></Badge>
            </div>
            
            <Popover ref="promoPopover">
                <div class="p-3 w-64">
                    <h4 class="font-bold text-md mb-2 border-b pb-2">Promociones Disponibles</h4>
                    <div class="space-y-3 max-h-48 overflow-y-auto">
                        <div v-for="promo in product.promotions" :key="promo.name" class="text-sm">
                            <p class="font-semibold">{{ promo.name }}</p>
                            <p class="text-xs text-gray-600">{{ getPromotionSummary(promo) }}</p>
                        </div>
                    </div>
                </div>
            </Popover>

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

            <div class="mb-3">
                <div v-if="product.price < product.original_price" class="flex items-baseline gap-2">
                    <p class="text-xl font-semibold text-red-600">${{ product.price.toFixed(2) }}</p>
                    <del class="text-md text-gray-500">${{ product.original_price.toFixed(2) }}</del>
                </div>
                <p v-else class="text-xl font-semibold text-gray-900 dark:text-gray-100">${{ product.price.toFixed(2) }}</p>
            </div>
            
            <Button :label="product.variants && Object.keys(product.variants).length > 0 ? 'Seleccionar variante' : 'Agregar al carrito'" icon="pi pi-plus" severity="warning" class="w-full mt-auto" @click="handlePrimaryAction"/>
        </div>
    </div>
</template>