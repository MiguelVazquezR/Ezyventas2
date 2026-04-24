<script setup>
import { computed } from 'vue';

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
        header="Resumen de inventario local" 
        :style="{ width: '95vw', maxWidth: '650px' }"
        :breakpoints="{ '640px': '100vw' }"
        :draggable="false"
        class="p-fluid"
    >
        <div class="flex flex-col gap-5 pt-2 font-sans">
            
            <!-- Métricas Rápidas (Grid Minimalista) -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="p-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/80 flex flex-col justify-center relative overflow-hidden">
                    <div class="absolute -right-4 -bottom-4 opacity-10">
                        <i class="pi pi-box text-6xl"></i>
                    </div>
                    <span class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Total Unidades Físicas</span>
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
                    <span class="text-[11px] text-gray-500">Ordenado por volumen físico</span>
                </div>

                <div v-if="stockByCategory && stockByCategory.length > 0" class="space-y-3 max-h-[300px] overflow-y-auto pr-2 custom-scrollbar">
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

            <!-- Panel de Inteligencia de Negocio / Explicación -->
            <div class="mt-2 bg-blue-50 dark:bg-blue-900/10 p-4 sm:p-5 rounded-xl border border-blue-100 dark:border-blue-800/50">
                <h4 class="text-sm font-bold text-blue-800 dark:text-blue-300 mb-2 flex items-center gap-2">
                    <i class="pi pi-lightbulb text-amber-500"></i> ¿Cómo interpretar esta información?
                </h4>
                <p class="text-[12px] text-blue-700/80 dark:text-blue-400/80 leading-relaxed mb-4">
                    Este panel contabiliza <strong>exclusivamente las unidades físicas reales</strong> que ocupan espacio en el almacén de esta sucursal. Es la herramienta ideal para preparar auditorías físicas y conocer el volumen exacto de tu mercancía inmovilizada.
                </p>
                
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="bg-white/80 dark:bg-gray-800/80 p-3 rounded-lg border border-blue-100 dark:border-blue-700/30">
                        <div class="flex items-center gap-1.5 mb-1.5 text-sky-600 dark:text-sky-400 font-bold text-[10px] uppercase tracking-wider">
                            <i class="pi pi-circle-fill text-[8px]"></i> Simples
                        </div>
                        <p class="text-[11px] text-gray-600 dark:text-gray-400 m-0 leading-tight">Artículos individuales con seguimiento de inventario directo.</p>
                    </div>
                    
                    <div class="bg-white/80 dark:bg-gray-800/80 p-3 rounded-lg border border-blue-100 dark:border-blue-700/30">
                        <div class="flex items-center gap-1.5 mb-1.5 text-violet-600 dark:text-violet-400 font-bold text-[10px] uppercase tracking-wider">
                            <i class="pi pi-circle-fill text-[8px]"></i> Variantes
                        </div>
                        <p class="text-[11px] text-gray-600 dark:text-gray-400 m-0 leading-tight">Suma total de todas las opciones físicas (ej. tallas, colores) de un mismo modelo.</p>
                    </div>
                    
                    <div class="bg-white/80 dark:bg-gray-800/80 p-3 rounded-lg border border-amber-200 dark:border-amber-700/30 relative">
                        <div class="flex items-center gap-1.5 mb-1.5 text-amber-600 dark:text-amber-400 font-bold text-[10px] uppercase tracking-wider">
                            <i class="pi pi-link text-[10px]"></i> Kits / Combos
                        </div>
                        <p class="text-[11px] text-gray-600 dark:text-gray-400 m-0 leading-tight">
                            <strong class="text-gray-800 dark:text-gray-200">Excluidos del conteo.</strong> Su disponibilidad es dinámica y depende de las piezas que lo forman. No ocupan un espacio físico extra como "Combo".
                        </p>
                    </div>
                </div>
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
.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background-color: #cbd5e1; /* gray-300 */
    border-radius: 10px;
}
.dark .custom-scrollbar::-webkit-scrollbar-thumb {
    background-color: #475569; /* gray-600 */
}
</style>