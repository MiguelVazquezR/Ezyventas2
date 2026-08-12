<script setup>
import { ref, computed } from 'vue';
import { Head, router, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import ManageStockModal from './Partials/ManageStockModal.vue';
import AdjustLayawayStockModal from './Partials/AdjustLayawayStockModal.vue';
import ActivityHistory from '@/Components/ActivityHistory.vue';
import PrintModal from '@/Components/PrintModal.vue';
import { usePermissions } from '@/Composables';

// --- Importación de Nuevos Componentes Parciales ---
import ProductOverviewCard from './Partials/ProductOverviewCard.vue';
import ProductInventoryCard from './Partials/ProductInventoryCard.vue';
import ProductPromotionsCard from './Partials/ProductPromotionsCard.vue';

const props = defineProps({
    product: Object,
    activities: Array,
    promotions: Array,
    availableTemplates: Array,
    activeLayaways: Array,
});

const confirm = useConfirm();
const { hasPermission } = usePermissions();

const hasOnlineStore = computed(() => usePage().props.auth.active_modules?.includes('module_online_store'));

const showManageStockModal = ref(false);
const showAdjustLayawayModal = ref(false);

// --- Lógica del Modal de Impresión ---
const isPrintModalVisible = ref(false);
const printDataSource = ref(null);

const openPrintModal = () => {
    printDataSource.value = {
        type: 'product',
        id: props.product.id
    };
    isPrintModalVisible.value = true;
};
// --- Fin de la lógica de impresión ---

// --- Helpers para Tipo de Producto ---
const isVariantProduct = computed(() => props.product.product_attributes && props.product.product_attributes.length > 0);
const isComposite = computed(() => props.product.components && props.product.components.length > 0);

// --- Lógica para Menú de Acciones ---
const actionMenu = ref(null);

const actionItems = ref([
    { label: 'Crear nuevo', icon: 'pi pi-plus', command: () => router.get(route('products.create')), visible: hasPermission('products.create') },
    { label: 'Editar', icon: 'pi pi-pencil', command: () => router.get(route('products.edit', props.product.id)), visible: hasPermission('products.edit') },
    { label: 'Agregar promoción', icon: 'pi pi-percentage', command: () => router.get(route('products.promotions.create', props.product.id)), visible: hasPermission('products.manage_promos') },
    { separator: true },
    { label: 'Entrada/salida stock', icon: 'pi pi-box', class: 'text-green-600', command: () => showManageStockModal.value = true, visible: hasPermission('products.manage_stock') && !isComposite.value },
    { separator: true },
    { label: 'Imprimir etiqueta', icon: 'pi pi-print', command: openPrintModal, visible: hasPermission('pos.access') },
    { separator: true },
    { label: 'Eliminar producto', icon: 'pi pi-trash', class: 'text-red-500', command: () => deleteProduct(), visible: hasPermission('products.delete') },
]);

const toggleActionMenu = (event) => {
    actionMenu.value.toggle(event);
};

const deleteProduct = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar "${props.product.name}"? Esta acción no se puede deshacer.`,
        header: 'Confirmar eliminación',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'Cancelar',
        accept: () => {
            router.delete(route('products.destroy', props.product.id));
        }
    });
};
</script>

<template>
    <Head :title="`Producto: ${product.name}`" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">

            <!-- Breadcrumb / Botón de regreso -->
            <div class="flex items-center">
                <Link :href="route('products.index')" class="inline-flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors">
                    <i class="pi pi-arrow-left !text-[10px]"></i> Volver al catálogo de productos
                </Link>
            </div>

            <!-- Header de la página al estilo Tesla UI -->
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
                <div>
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">{{ product.name }}</h1>
                    <div class="flex items-center gap-4 mt-3 flex-wrap">
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full" :class="product.show_in_pos ? 'bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.8)] animate-pulse' : 'bg-gray-400 dark:bg-gray-600'"></span>
                            {{ product.show_in_pos ? 'Visible en POS' : 'Insumo interno' }}
                        </p>

                        <span class="text-gray-300 dark:text-gray-700 hidden sm:block">|</span>

                        <div class="flex items-center gap-2">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Categoría:</span>
                            <span class="text-xs font-medium text-gray-900 dark:text-gray-100">{{ product.category?.name || 'Sin categoría' }}</span>
                        </div>

                        <span class="text-gray-300 dark:text-gray-700 hidden sm:block">|</span>

                        <div v-if="hasOnlineStore && product.show_online" class="flex items-center gap-2">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Tienda en línea:</span>
                            <span class="text-xs font-medium text-green-600 dark:text-green-400 flex items-center gap-1">
                                <span class="w-1.5 h-1.5 rounded-full bg-green-500" />
                                Visible
                            </span>
                        </div>

                        <span v-if="hasOnlineStore && product.show_online" class="text-gray-300 dark:text-gray-700 hidden sm:block">|</span>

                        <div v-if="hasOnlineStore && product.is_featured" class="flex items-center gap-2">
                            <i class="pi pi-star-fill text-yellow-500 !text-xs" />
                            <span class="text-xs font-medium text-yellow-600 dark:text-yellow-400">Destacado</span>
                        </div>
                    </div>
                </div>

                <div class="w-full md:w-auto shrink-0">
                    <Button label="Opciones" icon="pi pi-chevron-down" iconPos="right" @click="toggleActionMenu" severity="secondary" outlined class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold w-full md:w-auto" />
                    <Menu ref="actionMenu" :model="actionItems" :popup="true" />
                </div>
            </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            
            <!-- COLUMNA IZQUIERDA: Galería e Info Rápida -->
            <div class="lg:col-span-3">
                <ProductOverviewCard 
                    :product="product"
                    :isComposite="isComposite"
                    :isVariantProduct="isVariantProduct"
                    :hasPosAccess="hasPermission('pos.access')"
                    :canSeeCostPrice="hasPermission('products.see_cost_price')"
                    @print="openPrintModal"
                />
            </div>

            <!-- COLUMNA DERECHA: Inventario, Descripción, Promociones y Layouts -->
            <div class="lg:col-span-9 space-y-6">
                
                <!-- Sección: Descripción (Minimalista) -->
                <div v-if="product.description" class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3 m-0 flex items-center gap-2">
                        <i class="pi pi-align-left text-gray-400"></i> Descripción del producto
                    </h3>
                    <div class="prose prose-sm prose-gray dark:prose-invert max-w-none text-gray-600 dark:text-gray-400 leading-relaxed" v-html="product.description"></div>
                </div>

                <!-- Online store info (if applicable) -->
                <div v-if="hasOnlineStore && product.show_online" class="bg-emerald-50 dark:bg-emerald-900/10 p-6 rounded-3xl border border-emerald-100 dark:border-emerald-900/30">
                    <h3 class="text-sm font-semibold text-emerald-800 dark:text-emerald-300 mb-4 m-0 flex items-center gap-2">
                        <i class="pi pi-globe"></i> Información en tienda en línea
                    </h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="flex flex-col gap-1">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-emerald-600 dark:text-emerald-400 m-0">Estado</span>
                            <span class="text-sm font-medium text-emerald-800 dark:text-emerald-200 flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-green-500 shadow-[0_0_6px_rgba(34,197,94,0.6)] animate-pulse" />
                                Visible
                            </span>
                        </div>
                        <div class="flex flex-col gap-1">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-emerald-600 dark:text-emerald-400 m-0">Precio en línea</span>
                            <span class="text-sm font-semibold text-emerald-800 dark:text-emerald-200">
                                {{ product.online_price
                                    ? new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(product.online_price)
                                    : 'Igual que POS' }}
                            </span>
                        </div>
                        <div class="flex flex-col gap-1">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-emerald-600 dark:text-emerald-400 m-0">Destacado</span>
                            <span class="text-sm font-medium text-emerald-800 dark:text-emerald-200 flex items-center gap-1.5">
                                <i :class="product.is_featured ? 'pi pi-star-fill text-yellow-500' : 'pi pi-star text-gray-400'" />
                                {{ product.is_featured ? 'Sí' : 'No' }}
                            </span>
                        </div>
                        <div class="flex flex-col gap-1">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-emerald-600 dark:text-emerald-400 m-0">Slug</span>
                            <span class="text-sm font-mono text-emerald-800 dark:text-emerald-200">{{ product.slug || '—' }}</span>
                        </div>
                    </div>
                </div>

                <!-- Sección: Inventario y Variantes / Componentes -->
                <ProductInventoryCard 
                    :product="product"
                    :isComposite="isComposite"
                    :isVariantProduct="isVariantProduct"
                    :activeLayaways="activeLayaways"
                    :hasManageStockPermission="hasPermission('products.manage_stock')"
                    @adjust-stock="showManageStockModal = true"
                    @adjust-layaways="showAdjustLayawayModal = true"
                />

                <!-- Sección: Promociones -->
                <ProductPromotionsCard 
                    :promotions="promotions"
                    :hasManagePromosPermission="hasPermission('products.manage_promos')"
                />

                <!-- Sección: Historial de Actividad -->
                <div class="bg-white dark:bg-[#232323] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2 m-0 flex items-center gap-2">
                        <i class="pi pi-history text-gray-400"></i> Historial de movimientos
                    </h3>
                    <ActivityHistory :activities="activities" />
                </div>

            </div>
        </div>
        </div>

        <!-- Modales de la Vista -->
        <ManageStockModal v-if="product" :visible="showManageStockModal" :products="[product]"
            @update:visible="showManageStockModal = false" />

        <AdjustLayawayStockModal v-if="product" :visible="showAdjustLayawayModal" :product="product"
            @update:visible="showAdjustLayawayModal = false" />
            
        <PrintModal 
            v-if="printDataSource"
            v-model:visible="isPrintModalVisible"
            :data-source="printDataSource"
            :available-templates="availableTemplates"
        />
    </AppLayout>
</template>

<style scoped>
.prose { max-width: 100%; }
</style>