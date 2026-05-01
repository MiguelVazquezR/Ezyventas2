<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ManageCategoriesModal from '@/Components/ManageCategoriesModal.vue';
import ManageBrandsModal from '@/Components/ManageBrandsModal.vue';
import ManageProvidersModal from '@/Components/ManageProvidersModal.vue';
import ManageAttributesModal from './Partials/ManageAttributesModal.vue';
import { useConfirm } from 'primevue/useconfirm';

// Lógica de navegación
import FormNavigationSidebar from '@/Components/FormNavigationSidebar.vue';
import { useScrollspy } from '@/Composables/useScrollspy';

// Importación de Parciales Modulares
import GeneralInfo from './Partials/GeneralInfo.vue';
import Pricing from './Partials/Pricing.vue';
import Inventory from './Partials/Inventory.vue';
import Images from './Partials/Images.vue';

const props = defineProps({
    categories: Array,
    brands: Array,
    providers: Array,
    attributeDefinitions: Array,
    branches: Array,
    current_branch_id: Number,
    productLimitReached: Boolean,
});

const confirm = useConfirm();

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const items = ref([
    { label: 'Productos', url: route('products.index') },
    { label: 'Crear producto' }
]);

const form = useForm({
    name: '',
    description: '',
    sku: '',
    location: '', 
    branch_ids: [props.current_branch_id],
    category_id: null,
    brand_id: null,
    provider_id: null,
    cost_price: null,
    selling_price: null,
    show_in_pos: true,
    price_tiers: [],
    product_type: 'simple', // Arranca como simple
    current_stock: null,
    min_stock: null,
    max_stock: null,
    measure_unit: 'Pza',
    variants_matrix: [],
    composite_items: [],
    general_images: [],
    variant_images: [],
});

// Modales de Gestión
const showCategoryModal = ref(false);
const showBrandModal = ref(false);
const showProviderModal = ref(false);
const showAttributesModal = ref(false);

const localCategories = ref([...props.categories]);
const localBrands = ref([...props.brands]);
const localProviders = ref([...props.providers]);

// Callbacks para Modales
const handleNewCategory = (c) => { localCategories.value.push(c); form.category_id = c.id; };
const handleCategoryUpdate = (c) => { const idx = localCategories.value.findIndex(x => x.id === c.id); if (idx !== -1) localCategories.value[idx] = c; };
const handleCategoryDelete = (id) => { localCategories.value = localCategories.value.filter(c => c.id !== id); if (form.category_id === id) form.category_id = null; };

const handleNewBrand = (b) => { localBrands.value.push(b); form.brand_id = b.id; };
const handleBrandUpdate = (b) => { const idx = localBrands.value.findIndex(x => x.id === b.id); if (idx !== -1) localBrands.value[idx] = b; };
const handleBrandDelete = (id) => { localBrands.value = localBrands.value.filter(b => b.id !== id); if (form.brand_id === id) form.brand_id = null; };

const handleNewProvider = (p) => { localProviders.value.push(p); form.provider_id = p.id; };
const handleProviderUpdate = (p) => { const idx = localProviders.value.findIndex(x => x.id === p.id); if (idx !== -1) localProviders.value[idx] = p; };
const handleProviderDelete = (id) => { localProviders.value = localProviders.value.filter(p => p.id !== id); if (form.provider_id === id) form.provider_id = null; };

// --- LÓGICA DE NAVEGACIÓN ---
const formSections = [
    { id: 'general', label: 'Información general' },
    { id: 'inventory', label: 'Inventario y variantes' }, // Movido antes de Precios
    { id: 'pricing', label: 'Precios' },
    { id: 'images', label: 'Imágenes' }
];
const { activeSection, scrollTo } = useScrollspy(formSections.map(s => s.id));

const submit = () => {
    // Transformamos para quitar atributos temporales (como _localId)
    form.transform((data) => ({
        ...data,
        variants_matrix: data.variants_matrix.map(({ _localId, ...rest }) => rest)
    })).post(route('products.store'));
};
</script>

<template>
    <Head title="Crear producto" />
    <AppLayout>
        <Breadcrumb :home="home" :model="items" class="!bg-transparent !p-0" />
        <div class="mt-4">
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Registrar nuevo producto</h1>
        </div>

        <!-- VISTA DE LÍMITE ALCANZADO -->
        <div v-if="productLimitReached" class="mt-6 max-w-3xl mx-auto bg-white dark:bg-gray-800 p-8 rounded-lg shadow-md text-center">
            <div class="mb-6 flex justify-center">
                <div class="bg-gray-100 dark:bg-gray-700 w-24 h-24 rounded-full flex items-center justify-center">
                    <i class="pi pi-lock !text-5xl text-gray-400 dark:text-gray-500"></i>
                </div>
            </div>
            <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-200 mb-3">Límite de productos alcanzado</h2>
            <p class="text-gray-600 dark:text-gray-400 mb-8 max-w-lg mx-auto leading-relaxed">
                Has alcanzado la cantidad máxima de productos permitidos en tu plan actual. Para seguir ampliando tu catálogo, necesitas mejorar tu suscripción.
            </p>
            <div class="flex flex-col sm:flex-row justify-center gap-4">
                <Link :href="route('subscription.manage')">
                    <Button label="Mejorar suscripción" icon="pi pi-arrow-up" size="large" severity="primary" class="w-full sm:w-auto" />
                </Link>
                <Link :href="route('products.index')">
                    <Button label="Volver al catálogo" icon="pi pi-arrow-left" size="large" severity="secondary" outlined class="w-full sm:w-auto" />
                </Link>
            </div>
        </div>

        <!-- FORMULARIO ORQUESTADO -->
        <div v-else class="mt-6 flex flex-col md:flex-row gap-6 items-start relative">
            
            <!-- Sidebar de Navegación Refactorizado -->
            <FormNavigationSidebar :sections="formSections" :activeSection="activeSection" @scrollTo="scrollTo" />

            <!-- Contenedor Principal de Parciales -->
            <div class="w-full md:w-3/4">
                <form @submit.prevent="submit" class="space-y-6">
                    
                    <div id="general">
                        <GeneralInfo 
                            :form="form" 
                            :categories="localCategories" 
                            :brands="localBrands" 
                            :providers="localProviders" 
                            :branches="branches"
                            @open-category="showCategoryModal = true"
                            @open-brand="showBrandModal = true"
                            @open-provider="showProviderModal = true"
                        />
                    </div>

                    <!-- INVENTARIO AHORA VA ANTES DE PRECIOS -->
                    <div id="inventory">
                        <Inventory 
                            :form="form" 
                            :attributeDefinitions="attributeDefinitions"
                            @open-attributes="showAttributesModal = true"
                        />
                    </div>

                    <div id="pricing">
                        <Pricing :form="form" />
                    </div>

                    <div id="images">
                        <Images 
                            :form="form" 
                            :attributeDefinitions="attributeDefinitions"
                        />
                    </div>

                    <div class="flex justify-end sticky bottom-4 z-20">
                        <Button type="submit" label="Crear producto" icon="pi pi-check" severity="primary" size="large" :loading="form.processing" class="shadow-xl" />
                    </div>
                </form>
            </div>
        </div>

        <!-- Modales Independientes -->
        <ManageCategoriesModal v-model:visible="showCategoryModal" categoryType="product" @created="handleNewCategory" @updated="handleCategoryUpdate" @deleted="handleCategoryDelete" />
        <ManageBrandsModal v-model:visible="showBrandModal" @created="handleNewBrand" @updated="handleBrandUpdate" @deleted="handleBrandDelete" />
        <ManageProvidersModal v-model:visible="showProviderModal" @created="handleNewProvider" @updated="handleProviderUpdate" @deleted="handleProviderDelete" />
        <ManageAttributesModal v-if="form.category_id" v-model:visible="showAttributesModal" :category-id="form.category_id" />
        
        <!-- Usamos el ConfirmPopup para las eliminaciones dentro de los componentes parciales -->
        <ConfirmPopup group="price-tiers-delete" />
    </AppLayout>
</template>