<script setup>
import { computed } from 'vue';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import Divider from 'primevue/divider';

const props = defineProps({
    visible: Boolean,
    stockByCategory: {
        type: Array,
        default: () => []
    },
    totalStock: {
        type: Number,
        default: 0
    }
});

const emit = defineEmits(['update:visible']);

const close = () => {
    emit('update:visible', false);
};

// Totales desglosados globales
const globalSimpleStock = computed(() => {
    return props.stockByCategory.reduce((sum, cat) => sum + Number(cat.simple_stock || 0), 0);
});

const globalVariantStock = computed(() => {
    return props.stockByCategory.reduce((sum, cat) => sum + Number(cat.variant_stock || 0), 0);
});

const getPercentage = (amount) => {
    if (!props.totalStock || props.totalStock === 0) return 0;
    return Math.round((Number(amount) / props.totalStock) * 100);
};

const formatNum = (val) => new Intl.NumberFormat().format(val || 0);
</script>

<template>
    <Dialog 
        :visible="visible" 
        @update:visible="$emit('update:visible', $event)" 
        modal 
        header="Resumen de Inventario" 
        :style="{ width: '95vw', maxWidth: '600px' }"
        :breakpoints="{ '640px': '100vw' }"
        :draggable="false"
        class="p-fluid"
    >
        <div class="flex flex-col gap-5 pt-2 font-sans">
            
            <!-- Métricas Rápidas (Grid Minimalista) -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="p-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/80 flex flex-col justify-center">
                    <span class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Total Unidades</span>
                    <span class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ formatNum(totalStock) }}</span>
                </div>
                <div class="p-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800/40 flex flex-col justify-center">
                    <span class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider flex items-center gap-1.5 mb-1">
                        <i class="pi pi-circle-fill text-[8px] text-sky-500"></i> Simples
                    </span>
                    <span class="text-2xl font-semibold text-gray-700 dark:text-gray-200">{{ formatNum(globalSimpleStock) }}</span>
                </div>
                <div class="p-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800/40 flex flex-col justify-center">
                    <span class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider flex items-center gap-1.5 mb-1">
                        <i class="pi pi-circle-fill text-[8px] text-violet-500"></i> Variantes
                    </span>
                    <span class="text-2xl font-semibold text-gray-700 dark:text-gray-200">{{ formatNum(globalVariantStock) }}</span>
                </div>
            </div>

            <Divider class="!my-1" />

            <!-- Desglose por Categoría -->
            <div>
                <div class="flex justify-between items-end mb-3 px-1">
                    <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200">Desglose por categoría</h4>
                    <span class="text-[11px] text-gray-500">Ordenado por volumen</span>
                </div>

                <div v-if="stockByCategory && stockByCategory.length > 0" class="space-y-3">
                    <div v-for="cat in stockByCategory" :key="cat.id" 
                         class="group flex flex-col p-4 rounded-xl border border-gray-100 dark:border-gray-700/60 bg-white dark:bg-gray-900/20 hover:border-gray-300 dark:hover:border-gray-600 transition-colors">
                        
                        <div class="flex justify-between items-start mb-2.5">
                            <div>
                                <h5 class="text-sm font-bold text-gray-800 dark:text-gray-100 leading-tight">{{ cat.name }}</h5>
                                <span class="text-[11px] text-gray-500">{{ getPercentage(cat.products_sum_current_stock) }}% del inventario total</span>
                            </div>
                            <div class="text-right">
                                <span class="text-base font-bold text-gray-900 dark:text-white">{{ formatNum(cat.products_sum_current_stock) }}</span>
                                <span class="text-[10px] text-gray-400 font-medium ml-1 uppercase">uds</span>
                            </div>
                        </div>
                        
                        <!-- Barra de composición -->
                        <div class="flex h-1.5 w-full bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden mb-2.5">
                            <div 
                                class="bg-sky-500 h-full transition-all duration-700" 
                                v-tooltip.top="`Simples: ${cat.simple_stock} uds`"
                                :style="{ width: (Number(cat.simple_stock) / Number(cat.products_sum_current_stock) * 100) + '%' }"
                            ></div>
                            <div 
                                class="bg-violet-500 h-full transition-all duration-700" 
                                v-tooltip.top="`Variantes: ${cat.variant_stock} uds`"
                                :style="{ width: (Number(cat.variant_stock) / Number(cat.products_sum_current_stock) * 100) + '%' }"
                            ></div>
                        </div>

                        <!-- Sub-métricas (Ocultan el valor si es 0 para mayor limpieza) -->
                        <div class="flex items-center gap-4 text-[11px] text-gray-500 dark:text-gray-400">
                            <span v-if="cat.simple_stock > 0">Simples: <span class="font-semibold text-gray-700 dark:text-gray-300">{{ formatNum(cat.simple_stock) }}</span></span>
                            <span v-if="cat.variant_stock > 0">Variantes: <span class="font-semibold text-gray-700 dark:text-gray-300">{{ formatNum(cat.variant_stock) }}</span></span>
                        </div>
                    </div>
                </div>

                <div v-else class="text-center py-8 text-gray-400 border border-dashed border-gray-200 dark:border-gray-700 rounded-xl">
                    <span class="text-sm">No hay datos de inventario registrados.</span>
                </div>
            </div>

            <!-- Nota Explicativa Minimalista -->
            <div class="bg-gray-50 dark:bg-gray-800/40 p-4 rounded-xl border border-gray-200 dark:border-gray-700/60 flex items-start gap-3 mt-2">
                <i class="pi pi-info-circle text-gray-400 mt-0.5"></i>
                <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-relaxed m-0">
                    El <strong>Stock Total</strong> representa las existencias físicas en esta sucursal específica. Para productos con múltiples opciones (tallas, colores), se suma el inventario de todas sus variantes locales.
                </p>
            </div>
        </div>

        <template #footer>
            <div class="flex justify-end pt-2">
                <Button label="Entendido" @click="close" outlined severity="secondary" class="!px-6" />
            </div>
        </template>
    </Dialog>
</template>

<style scoped>
:deep(.p-dialog-content) {
    scrollbar-width: thin;
}
</style>