<script setup>
import { computed } from 'vue';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import Divider from 'primevue/divider';
import Tag from 'primevue/tag';

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
        header="Análisis de Inventario" 
        :style="{ width: '95vw', maxWidth: '600px' }"
        :breakpoints="{ '640px': '100vw' }"
        :draggable="false"
        class="p-fluid"
    >
        <div class="flex flex-col gap-6 pt-2">
            
            <!-- Resumen General -->
            <div class="bg-gray-900 text-white rounded-2xl p-6 shadow-lg relative overflow-hidden">
                <div class="relative z-10">
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Stock Total en Sucursal</h3>
                    <div class="text-5xl font-black mb-4">{{ formatNum(totalStock) }} <span class="text-lg font-normal text-gray-500">unidades</span></div>
                    
                    <div class="flex gap-4 border-t border-white/10 pt-4">
                        <div class="flex-1">
                            <span class="block text-[10px] text-gray-400 uppercase mb-1">Productos Simples</span>
                            <span class="text-lg font-bold">{{ formatNum(globalSimpleStock) }}</span>
                        </div>
                        <div class="w-px bg-white/10"></div>
                        <div class="flex-1">
                            <span class="block text-[10px] text-gray-400 uppercase mb-1">Productos con Variantes</span>
                            <span class="text-lg font-bold">{{ formatNum(globalVariantStock) }}</span>
                        </div>
                    </div>
                </div>
                <!-- Decoración fondo -->
                <i class="pi pi-box absolute -bottom-4 -right-4 text-8xl opacity-10 rotate-12"></i>
            </div>

            <!-- Listado por Categoría -->
            <div>
                <div class="flex justify-between items-center mb-4">
                    <h4 class="font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                        <i class="pi pi-list text-primary-500"></i>
                        Desglose Detallado
                    </h4>
                    <span class="text-xs text-gray-500 italic">Ordenado por mayor stock</span>
                </div>

                <div v-if="stockByCategory && stockByCategory.length > 0" class="space-y-6">
                    <div v-for="cat in stockByCategory" :key="cat.id" class="p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:shadow-md transition-shadow">
                        <div class="flex justify-between items-start mb-3">
                            <div>
                                <span class="font-bold text-gray-800 dark:text-gray-100 block">{{ cat.name }}</span>
                                <span class="text-[10px] text-gray-400 uppercase tracking-tighter">Impacto: {{ getPercentage(cat.products_sum_current_stock) }}% del total</span>
                            </div>
                            <div class="text-right">
                                <span class="text-xl font-black text-primary-600 dark:text-primary-400">{{ formatNum(cat.products_sum_current_stock) }}</span>
                                <span class="text-[10px] block text-gray-500">UNIDADES</span>
                            </div>
                        </div>
                        
                        <!-- Barra de composición -->
                        <div class="flex h-1.5 w-full bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden mb-3">
                            <div 
                                class="bg-blue-500 h-full transition-all duration-700" 
                                v-tooltip.top="`Simples: ${cat.simple_stock} uds`"
                                :style="{ width: (Number(cat.simple_stock) / Number(cat.products_sum_current_stock) * 100) + '%' }"
                            ></div>
                            <div 
                                class="bg-purple-500 h-full transition-all duration-700" 
                                v-tooltip.top="`Variantes: ${cat.variant_stock} uds`"
                                :style="{ width: (Number(cat.variant_stock) / Number(cat.products_sum_current_stock) * 100) + '%' }"
                            ></div>
                        </div>

                        <!-- Mini Leyenda por categoría -->
                        <div class="flex items-center gap-4 text-[11px]">
                            <div class="flex items-center gap-1.5 text-gray-600 dark:text-gray-400">
                                <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                                <span>Simples: <strong>{{ formatNum(cat.simple_stock) }}</strong></span>
                            </div>
                            <div class="flex items-center gap-1.5 text-gray-600 dark:text-gray-400">
                                <span class="w-2 h-2 rounded-full bg-purple-500"></span>
                                <span>Variantes: <strong>{{ formatNum(cat.variant_stock) }}</strong></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-else class="text-center py-10 text-gray-400 italic">
                    <i class="pi pi-folder-open text-4xl mb-2 block opacity-20"></i>
                    No hay datos de inventario disponibles.
                </div>
            </div>

            <!-- Nota Explicativa -->
            <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-xl border border-blue-100 dark:border-blue-800/50 flex gap-3">
                <i class="pi pi-info-circle text-blue-500 mt-0.5"></i>
                <div class="text-[11px] text-blue-800 dark:text-blue-300 leading-relaxed">
                    <strong>¿Cómo se calcula esto?</strong><br>
                    Este resumen analiza en tiempo real todas las sucursales vinculadas a este producto. El stock de variantes se calcula sumando individualmente cada combinación (talla, color, etc.) que tiene existencias registradas en esta sucursal específica.
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex justify-between items-center w-full">
                <div class="flex gap-2 text-[10px] uppercase font-bold opacity-50">
                    <span class="flex items-center gap-1"><i class="pi pi-circle-fill text-blue-500"></i> Simples</span>
                    <span class="flex items-center gap-1"><i class="pi pi-circle-fill text-purple-500"></i> Variantes</span>
                </div>
                <Button label="Entendido" @click="close" class="p-button-text p-button-sm" />
            </div>
        </template>
    </Dialog>
</template>

<style scoped>
:deep(.p-dialog-content) {
    scrollbar-width: thin;
}
</style>