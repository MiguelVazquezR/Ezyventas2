<script setup>
const props = defineProps({
    product: {
        type: Object,
        required: true
    },
    canSeeDetails: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['goToDetails']);

// --- HELPER FUNCTIONS PARA STOCK Y VARIANTES ---
const getVariants = (product) => {
    if (!product) return [];
    return product.product_attributes || product.productAttributes || [];
};

const hasVariants = (product) => {
    return getVariants(product).length > 0;
};

const getCalculatedStock = (product) => {
    if (!product) return 0;
    if (hasVariants(product)) {
        return getVariants(product).reduce((sum, v) => sum + (Number(v.current_stock) || 0), 0);
    }
    return Number(product.current_stock) || 0;
};

const getCalculatedReserved = (product) => {
    if (!product) return 0;
    if (hasVariants(product)) {
        return getVariants(product).reduce((sum, v) => sum + (Number(v.reserved_stock) || 0), 0);
    }
    return Number(product.reserved_stock) || 0;
};

const getAvailableStock = (product) => {
    return getCalculatedStock(product) - getCalculatedReserved(product);
};
</script>

<template>
    <div class="flex flex-col h-full -mt-2">
        <!-- Contenedor scrolleable -->
        <div class="flex-1 overflow-y-auto pb-24 space-y-4 px-1">

            <!-- Tarjeta Principal (Imagen, nombre, precio) -->
            <div
                class="bg-white dark:bg-gray-800 rounded-lg p-5 shadow-sm border border-gray-200 dark:border-gray-700">
                <div class="flex gap-5 items-start">
                    <div
                        class="w-24 h-24 shrink-0 bg-gray-100 dark:bg-gray-700 rounded-lg overflow-hidden border border-gray-200 dark:border-gray-600">
                        <img v-if="product.media && product.media.length > 0"
                            :src="product.media[0].original_url"
                            class="w-full h-full object-cover" />
                        <div v-else class="w-full h-full flex items-center justify-center">
                            <i class="pi pi-image text-3xl text-gray-400"></i>
                        </div>
                    </div>
                    <div class="flex-1">
                        <div class="flex justify-between items-start gap-2 mb-1">
                            <h3 class="font-bold text-gray-900 dark:text-gray-100 leading-tight m-0">{{ product.name }}</h3>
                            <!-- INDICADOR DE POS / INSUMO -->
                            <Tag v-if="product.show_in_pos" severity="success" value="Venta POS" class="!text-[10px] shrink-0" icon="pi pi-shop" />
                            <Tag v-else severity="secondary" value="Insumo" class="!text-[10px] shrink-0" icon="pi pi-eye-slash" v-tooltip.top="'No visible en punto de venta'" />
                        </div>

                        <div class="text-sm text-gray-600 dark:text-gray-400 mb-1">
                            SKU: <span class="font-mono text-gray-800 dark:text-gray-200">{{
                                product.sku || 'N/A' }}</span>
                        </div>
                        <div class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                            Categoría: <span class="text-gray-800 dark:text-gray-200">{{
                                product.category?.name || 'N/A' }}</span>
                        </div>
                        <div
                            class="text-sm text-gray-600 dark:text-gray-400 mb-3 flex flex-wrap items-center gap-1">
                            Sucursales:
                            <Tag v-for="branch in product.branches" :key="branch.id"
                                :value="branch.name" severity="info" class="!text-[10px]" />
                        </div>
                        <div class="text-lg font-bold text-primary-600 dark:text-primary-400">
                            {{ new Intl.NumberFormat('es-MX', {
                                style: 'currency', currency: 'MXN'
                            }).format(product.selling_price) }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Resumen de Inventario General de la Sucursal -->
            <div
                class="bg-white dark:bg-gray-800 rounded-lg p-5 shadow-sm border border-gray-200 dark:border-gray-700">
                <h4 class="font-bold text-sm text-gray-800 dark:text-gray-200 mb-4 flex items-center gap-2">
                    <i class="pi pi-warehouse"></i> Inventario local

                    <span v-if="hasVariants(product)"
                        class="text-xs font-normal text-gray-500 bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded-full"
                        v-tooltip.top="'Cálculo sumando todas las variantes de este producto'">
                        (Total de Variantes)
                    </span>
                </h4>

                <div class="grid grid-cols-2 gap-y-5 gap-x-4 text-sm">
                    <div
                        class="bg-gray-50 dark:bg-gray-900/50 p-3 rounded border border-gray-100 dark:border-gray-700">
                        <span
                            class="block text-gray-500 dark:text-gray-400 text-xs uppercase tracking-wider mb-1">Stock
                            Físico</span>
                        <span class="font-semibold text-xl text-gray-800 dark:text-gray-200">{{
                            getCalculatedStock(product) }}</span>
                    </div>
                    <div
                        class="bg-gray-50 dark:bg-gray-900/50 p-3 rounded border border-gray-100 dark:border-gray-700">
                        <span
                            class="block text-gray-500 dark:text-gray-400 text-xs uppercase tracking-wider mb-1">Disponible</span>
                        <span class="font-bold text-xl text-green-600">{{
                            getAvailableStock(product)
                            }}</span>
                    </div>
                    <div
                        class="bg-gray-50 dark:bg-gray-900/50 p-3 rounded border border-gray-100 dark:border-gray-700">
                        <span
                            class="block text-gray-500 dark:text-gray-400 text-xs uppercase tracking-wider mb-1">Apartados</span>
                        <span class="font-semibold text-lg text-indigo-500">{{
                            getCalculatedReserved(product) }}</span>
                    </div>
                    <div
                        class="bg-gray-50 dark:bg-gray-900/50 p-3 rounded border border-gray-100 dark:border-gray-700">
                        <span
                            class="block text-gray-500 dark:text-gray-400 text-xs uppercase tracking-wider mb-1">Ubicación</span>
                        <span class="font-medium text-lg text-gray-800 dark:text-gray-200">
                            {{ hasVariants(product) ? 'Múltiples' : (product.location || '--') }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- SECCIÓN DINÁMICA DE VARIANTES EN EL DRAWER -->
            <div v-if="hasVariants(product)"
                class="bg-white dark:bg-gray-800 rounded-lg p-5 shadow-sm border border-gray-200 dark:border-gray-700">
                <h4 class="font-bold text-sm text-gray-800 dark:text-gray-200 mb-4 flex items-center gap-2">
                    <i class="pi pi-sitemap"></i> Variantes ({{ getVariants(product).length }})
                </h4>
                <div class="space-y-3">
                    <div v-for="variant in getVariants(product)" :key="variant.id"
                        class="bg-gray-50 dark:bg-gray-900/50 p-3 rounded-lg border border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row justify-between gap-3">
                        <div>
                            <div class="flex flex-wrap gap-1.5 mb-2">
                                <Tag v-for="(val, key) in variant.attributes" :key="key"
                                    :value="`${key}: ${val}`" severity="secondary" class="!text-xs" />
                            </div>
                            <!-- Precio calculado de la variante -->
                            <div class="font-bold text-primary-600 dark:text-primary-400 text-sm mb-1">
                                {{ new Intl.NumberFormat('es-MX', {
                                    style: 'currency', currency: 'MXN'
                                }).format(Number(product.selling_price) +
                                Number(variant.price_modifier
                                || variant.selling_price_modifier || 0)) }}
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                SKU: <span class="font-mono text-gray-800 dark:text-gray-200">{{ variant.sku ||
                                    variant.sku_suffix || 'N/A' }}</span>
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1" v-if="variant.location">
                                <i class="pi pi-map-marker text-[10px] mr-1"></i>{{ variant.location }}
                            </div>
                        </div>
                        <div
                            class="text-left sm:text-right border-t sm:border-t-0 border-gray-200 dark:border-gray-700 pt-2 sm:pt-0">
                            <div class="text-sm font-semibold text-gray-600 dark:text-gray-300">
                                Físico: {{ variant.current_stock || 0 }}
                            </div>
                            <div class="text-sm font-bold text-green-600 mt-1">
                                Disp: {{ (variant.current_stock || 0) - (variant.reserved_stock || 0) }}
                            </div>
                            <div v-if="variant.reserved_stock > 0"
                                class="text-xs text-indigo-500 font-medium mt-1">
                                ({{ variant.reserved_stock }} apartados)
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer (Botón flotante en el Drawer) -->
        <div
            class="absolute bottom-0 left-0 w-full p-4 bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700">
            <Button label="Ver todos los detalles" icon="pi pi-external-link" class="w-full" size="large"
                severity="primary" @click="$emit('goToDetails', product.id)"
                :disabled="!canSeeDetails" />
        </div>
    </div>
</template>