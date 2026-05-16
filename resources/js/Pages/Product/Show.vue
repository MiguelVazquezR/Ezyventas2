<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import ManageStockModal from './Partials/ManageStockModal.vue';
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

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const items = ref([
    { label: 'Productos', url: route('products.index') },
    { label: props.product.name }
]);

const showManageStockModal = ref(false);

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
        <Breadcrumb :home="home" :model="items" class="!bg-transparent !p-0" />

        <!-- Header Minimalista -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-end mt-2 mb-6 gap-4">
            <div>
                <div class="flex items-center gap-3 mb-1">
                    <Tag v-if="product.is_on_sale" severity="danger" value="En Oferta" rounded></Tag>
                    <Tag v-if="product.is_featured" severity="info" value="Destacado" rounded></Tag>
                    <!-- INDICADOR DE POS / INSUMO / KIT -->
                    <Tag v-if="product.show_in_pos" severity="success" value="Venta POS" rounded icon="pi pi-shop"></Tag>
                    <Tag v-else severity="secondary" value="Insumo" rounded icon="pi pi-eye-slash" v-tooltip.top="'Oculto en el Punto de Venta'"></Tag>
                    <Tag v-if="isComposite" severity="contrast" value="Kit/Combo" rounded icon="pi pi-link"></Tag>
                    <Tag v-if="product.is_bulk" severity="primary" value="Venta a granel" rounded></Tag>
                    <span class="text-xs font-semibold text-gray-500 tracking-wider uppercase">
                        {{ product.category?.name || 'Sin categoría' }}
                    </span>
                </div>
                <h1 class="text-3xl font-black text-gray-900 dark:text-white tracking-tight leading-none">{{ product.name }}</h1>
            </div>
            
            <!-- Menú de Acciones Principal -->
            <div>
                <Button label="Acciones" icon="pi pi-chevron-down" iconPos="right" @click="toggleActionMenu" severity="secondary" outlined class="w-full sm:w-auto" />
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
                <div v-if="product.description" class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60">
                    <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3 flex items-center gap-2">
                        <i class="pi pi-align-left text-gray-400"></i> Descripción del producto
                    </h3>
                    <div class="prose prose-sm prose-gray dark:prose-invert max-w-none text-gray-600 dark:text-gray-400 leading-relaxed" v-html="product.description"></div>
                </div>

                <!-- Sección: Inventario y Variantes / Componentes -->
                <ProductInventoryCard 
                    :product="product"
                    :isComposite="isComposite"
                    :isVariantProduct="isVariantProduct"
                    :activeLayaways="activeLayaways"
                    :hasManageStockPermission="hasPermission('products.manage_stock')"
                    @adjust-stock="showManageStockModal = true"
                />

                <!-- Sección: Promociones -->
                <ProductPromotionsCard 
                    :promotions="promotions"
                    :hasManagePromosPermission="hasPermission('products.manage_promos')"
                />

                <!-- Sección: Historial de Actividad -->
                <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60">
                    <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-2 flex items-center gap-2">
                        <i class="pi pi-history text-gray-400"></i> Historial de movimientos
                    </h3>
                    <ActivityHistory :activities="activities" />
                </div>

            </div>
        </div>

        <!-- Modales de la Vista -->
        <ManageStockModal v-if="product" :visible="showManageStockModal" :products="[product]"
            @update:visible="showManageStockModal = false" />
            
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