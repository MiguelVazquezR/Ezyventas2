<script setup>
import { ref, computed } from 'vue';
import { useToast } from "primevue/usetoast";

const props = defineProps({
    product: Object,
    isComposite: Boolean,
    isVariantProduct: Boolean,
    hasPosAccess: Boolean,
    canSeeCostPrice: Boolean
});

const emit = defineEmits(['print']);
const toast = useToast();

const copyToClipboard = (text) => {
    navigator.clipboard.writeText(text).then(() => {
        toast.add({ severity: 'success', summary: 'Copiado', detail: 'SKU copiado al portapapeles', life: 3000 });
    });
};

const formatCurrency = (value) => {
    if (value === null || value === undefined) return 'N/A';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

// --- LÓGICA DE UTILIDAD (PROFIT MARGIN) ---
const baseProfit = computed(() => {
    const cost = Number(props.product.cost_price);
    const sell = Number(props.product.selling_price);

    if (cost > 0 && sell > 0) {
        const profitAmount = sell - cost;
        const marginPercentage = (profitAmount / cost) * 100;
        const isLoss = profitAmount < 0;

        return {
            percentage: marginPercentage.toFixed(1),
            isLoss
        };
    }
    return null;
});

const getTierProfit = (tierPrice) => {
    const cost = Number(props.product.cost_price);
    const price = Number(tierPrice);
    
    if (cost > 0 && price > 0) {
        const profit = price - cost;
        const percentage = (profit / cost) * 100;
        return { percentage: percentage.toFixed(1), isLoss: profit < 0 };
    }
    return null;
};

const generalImages = computed(() =>
    (props.product.media || []).filter(m => m.collection_name === 'product-general-images')
);

const selectedImageIndex = ref(0);

const currentGeneralImage = computed(() => {
    if (generalImages.value && generalImages.value.length > 0) {
        if (selectedImageIndex.value >= generalImages.value.length) {
            selectedImageIndex.value = 0;
        }
        return generalImages.value[selectedImageIndex.value];
    }
    return null;
});

const priceTiers = computed(() => {
    if (!props.product.price_tiers || !Array.isArray(props.product.price_tiers)) return [];
    return [...props.product.price_tiers].sort((a, b) => a.min_quantity - b.min_quantity);
});
</script>

<template>
    <div class="space-y-6">
        <!-- Galería Limpia -->
        <div class="bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] p-4">
            <div v-if="generalImages.length > 0">
                <div class="flex justify-center mb-3 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl overflow-hidden">
                    <Image :src="currentGeneralImage?.original_url" :alt="product.name" preview 
                        imageClass="w-full h-56 object-contain p-2 transition-transform duration-300 hover:scale-105" />
                </div>
                
                <!-- Miniaturas -->
                <div v-if="generalImages.length > 1" class="flex gap-2 overflow-x-auto py-1">
                    <button v-for="(img, index) in generalImages" :key="img.id" 
                        @click="selectedImageIndex = index"
                        class="relative rounded-lg overflow-hidden border-2 transition-all flex-shrink-0 focus:outline-none h-14 w-14"
                        :class="selectedImageIndex === index ? 'border-primary-500' : 'border-transparent opacity-60 hover:opacity-100'">
                        <img :src="img.original_url" :alt="img.name" class="w-full h-full object-cover bg-gray-100 dark:bg-gray-700" />
                    </button>
                </div>
            </div>
            
            <div v-else class="text-center text-gray-400 dark:text-gray-500 py-12 flex flex-col items-center bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl">
                <i class="pi pi-image !text-4xl mb-3 opacity-50"></i>
                <span class="text-sm font-medium">Sin imagen general</span>
            </div>
        </div>

        <!-- Tarjetas de Información Rápida (Grid) -->
        <div class="grid grid-cols-2 gap-3">
            <div class="bg-white dark:bg-[#232323] p-4 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <div class="flex justify-between items-start mb-1">
                    <span class="text-[10px] text-gray-500 uppercase font-bold tracking-wider">SKU</span>
                    <div class="flex gap-1">
                        <i v-if="product.sku" @click="copyToClipboard(product.sku)" class="pi pi-copy text-gray-400 hover:text-primary-500 cursor-pointer text-xs transition-colors" v-tooltip.top="'Copiar'"></i>
                        <i v-if="product.sku && hasPosAccess" @click="$emit('print')" class="pi pi-print text-gray-400 hover:text-primary-500 cursor-pointer text-xs transition-colors" v-tooltip.top="'Imprimir'"></i>
                    </div>
                </div>
                <div class="font-mono font-medium text-gray-900 dark:text-gray-100 truncate text-sm" :title="product.sku">{{ product.sku || 'N/A' }}</div>
            </div>
            <div class="bg-white dark:bg-gray-800 p-4 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700/60">
                <span class="text-[10px] block text-gray-500 uppercase font-bold tracking-wider mb-1">Ubicación</span>
                <div class="font-medium text-gray-900 dark:text-gray-100 text-sm truncate" :title="product.location">
                    {{ isComposite ? '--' : (isVariantProduct ? 'Múltiples' : (product.location || '--')) }}
                </div>
            </div>
            <div class="bg-white dark:bg-gray-800 p-4 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700/60">
                <span class="text-[10px] block text-gray-500 uppercase font-bold tracking-wider mb-1">Marca</span>
                <div class="font-medium text-gray-900 dark:text-gray-100 text-sm truncate">{{ product.brand?.name || '--' }}</div>
            </div>
            <div class="bg-white dark:bg-gray-800 p-4 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700/60">
                <span class="text-[10px] block text-gray-500 uppercase font-bold tracking-wider mb-1">Proveedor</span>
                <div class="font-medium text-gray-900 dark:text-gray-100 text-sm truncate">{{ product.provider?.name || '--' }}</div>
            </div>
        </div>

        <!-- Detalles de Precios Avanzados -->
        <div class="bg-white dark:bg-[#232323] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
            <h3 class="text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-4 m-0 flex items-center gap-2">
                <i class="pi pi-tag text-primary-500"></i> Estructura de precios
            </h3>
            
            <div class="flex justify-between items-center py-2">
                <span class="text-sm text-gray-600 dark:text-gray-400">Precio de venta</span>
                <div class="flex flex-col items-end">
                    <span class="text-xl font-light tracking-tight text-gray-900 dark:text-white">{{ formatCurrency(product.selling_price) }}</span>
                    <div v-if="canSeeCostPrice && baseProfit" 
                         :class="['text-[11px] mt-0.5 font-medium flex items-center gap-1', baseProfit.isLoss ? 'text-red-500' : 'text-green-600 dark:text-green-400']">
                        <i :class="baseProfit.isLoss ? 'pi pi-arrow-down !text-[9px]' : 'pi pi-arrow-up !text-[9px]'"></i>
                        Margen: {{ baseProfit.percentage }}%
                    </div>
                </div>
            </div>

            <!-- Online price -->
            <div v-if="product.show_online && product.online_price" class="flex justify-between items-center py-2 border-t border-gray-100 dark:border-[#3a3a3a] mt-1">
                <span class="text-sm text-emerald-600 dark:text-emerald-400 flex items-center gap-1.5">
                    <i class="pi pi-globe !text-xs" /> Precio en línea
                </span>
                <span class="text-sm font-semibold text-emerald-600 dark:text-emerald-400">{{ formatCurrency(product.online_price) }}</span>
            </div>

            <div v-if="canSeeCostPrice" class="flex justify-between items-center py-2 border-t border-gray-100 dark:border-[#3a3a3a] mt-1">
                <span class="text-sm text-gray-600 dark:text-gray-400">Precio de compra</span>
                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ formatCurrency(product.cost_price) }}</span>
            </div>

            <div v-if="priceTiers.length > 0" class="mt-4 pt-4 border-t border-gray-100 dark:border-[#3a3a3a]">
                <span class="text-[10px] text-gray-500 dark:text-gray-400 block mb-3 uppercase tracking-widest font-bold">Precios por volumen:</span>
                <div class="space-y-2">
                    <div v-for="(tier, index) in priceTiers" :key="index" class="flex justify-between items-center text-sm bg-gray-50 dark:bg-[#1a1a1a] px-3 py-2 rounded-xl">
                        <span class="text-gray-600 dark:text-gray-400">Desde <span class="font-bold">{{ tier.min_quantity }}</span> uds</span>
                        
                        <div class="flex flex-col items-end">
                            <span class="font-bold text-primary-600 dark:text-primary-400">{{ formatCurrency(tier.price) }}</span>
                            <!-- Indicador de Utilidad Nivel (Tier) -->
                            <div v-if="canSeeCostPrice && getTierProfit(tier.price)" 
                                 :class="['text-[10px] mt-0.5 font-medium flex items-center gap-1', getTierProfit(tier.price).isLoss ? 'text-red-500' : 'text-green-600 dark:text-green-400']">
                                <i :class="getTierProfit(tier.price).isLoss ? 'pi pi-arrow-down !text-[8px]' : 'pi pi-arrow-up !text-[8px]'"></i>
                                {{ getTierProfit(tier.price).percentage }}%
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>