<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ManageCategoriesModal from '@/Components/ManageCategoriesModal.vue';
import ManageBrandsModal from '@/Components/ManageBrandsModal.vue';
import ManageProvidersModal from '@/Components/ManageProvidersModal.vue';
import ManageAttributesModal from './Partials/ManageAttributesModal.vue';

// Importación de Parciales Modulares Compartidos
import GeneralInfo from './Partials/GeneralInfo.vue';
import Pricing from './Partials/Pricing.vue';
import Inventory from './Partials/Inventory.vue';
import Images from './Partials/Images.vue';

const props = defineProps({
    product: Object,
    categories: Array,
    brands: Array,
    providers: Array,
    attributeDefinitions: Array,
    branches: Array,
});

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const items = ref([
    { label: 'Productos', url: route('products.index') },
    { label: `Editar: ${props.product.name}` }
]);

// Transformamos los product_attributes de la BD al formato del Frontend (variants_matrix)
const initialVariantsMatrix = (props.product.product_attributes || []).map(pa => ({
    id: pa.id,
    _localId: `db_${pa.id}`,
    attributes: pa.attributes,
    sku: pa.sku_suffix || pa.sku || '',
    selling_price_modifier: parseFloat(pa.selling_price_modifier || 0),
    final_price: parseFloat(props.product.selling_price || 0) + parseFloat(pa.selling_price_modifier || 0),
    current_stock: pa.current_stock || 0,
}));

// Inyección de la data de la Base de datos al formulario reactivo
const form = useForm({
    _method: 'PUT',
    name: props.product.name,
    description: props.product.description,
    sku: props.product.sku,
    location: props.product.location,
    branch_ids: props.product.branches ? props.product.branches.map(b => b.id) : [],
    category_id: props.product.category_id,
    brand_id: props.product.brand_id,
    provider_id: props.product.provider_id,
    cost_price: props.product.cost_price ? parseFloat(props.product.cost_price) : null,
    selling_price: props.product.selling_price ? parseFloat(props.product.selling_price) : null,
    price_tiers: props.product.price_tiers || [],
    product_type: props.product.product_attributes && props.product.product_attributes.length > 0 ? 'variant' : 'simple',
    current_stock: props.product.current_stock,
    min_stock: props.product.min_stock,
    max_stock: props.product.max_stock,
    measure_unit: props.product.measure_unit || 'Pza',
    variants_matrix: initialVariantsMatrix,
    general_images: [],
    variant_images: {},
    deleted_media_ids: [], // Arreglo vital para borrar fotos existentes
});

// Modales de Gestión
const showCategoryModal = ref(false);
const showBrandModal = ref(false);
const showProviderModal = ref(false);
const showAttributesModal = ref(false);

const localCategories = ref([...props.categories]);
const localBrands = ref([...props.brands]);
const localProviders = ref([...props.providers]);

// Callbacks Modales
const handleNewCategory = (c) => { localCategories.value.push(c); form.category_id = c.id; };
const handleCategoryUpdate = (c) => { const idx = localCategories.value.findIndex(x => x.id === c.id); if (idx !== -1) localCategories.value[idx] = c; };
const handleCategoryDelete = (id) => { localCategories.value = localCategories.value.filter(c => c.id !== id); if (form.category_id === id) form.category_id = null; };

const handleNewBrand = (b) => { localBrands.value.push(b); form.brand_id = b.id; };
const handleBrandUpdate = (b) => { const idx = localBrands.value.findIndex(x => x.id === b.id); if (idx !== -1) localBrands.value[idx] = b; };
const handleBrandDelete = (id) => { localBrands.value = localBrands.value.filter(b => b.id !== id); if (form.brand_id === id) form.brand_id = null; };

const handleNewProvider = (p) => { localProviders.value.push(p); form.provider_id = p.id; };
const handleProviderUpdate = (p) => { const idx = localProviders.value.findIndex(x => x.id === p.id); if (idx !== -1) localProviders.value[idx] = p; };
const handleProviderDelete = (id) => { localProviders.value = localProviders.value.filter(p => p.id !== id); if (form.provider_id === id) form.provider_id = null; };

// --- LÓGICA DE SCROLLSPY ---
const activeSection = ref('general');
let observer = null;
let isManualScrolling = false;

const scrollTo = (id) => {
    isManualScrolling = true;
    activeSection.value = id;
    const element = document.getElementById(id);
    if (element) {
        element.scrollIntoView({ behavior: 'smooth' });
        setTimeout(() => { isManualScrolling = false; }, 800);
    }
};

onMounted(() => {
    const options = { root: null, rootMargin: '-20% 0px -50% 0px', threshold: 0 };
    observer = new IntersectionObserver((entries) => {
        if (isManualScrolling) return; 
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                activeSection.value = entry.target.id;
            }
        });
    }, options);

    setTimeout(() => {
        ['general', 'pricing', 'inventory', 'images'].forEach(id => {
            const el = document.getElementById(id);
            if (el) observer.observe(el);
        });
    }, 300);
});

onUnmounted(() => { if (observer) observer.disconnect(); });

// --- ENVÍO DEL FORMULARIO ---
const submit = () => {
    form.transform((data) => {
        // Limpiar propiedades temporales y ordenar niveles de precio antes de enviar
        const transformedMatrix = data.variants_matrix.map(({ _localId, final_price, ...rest }) => rest);
        const cleanedPriceTiers = (data.price_tiers || [])
            .filter(tier => tier.min_quantity > 1 && tier.price > 0)
            .sort((a, b) => a.min_quantity - b.min_quantity);

        return {
            ...data,
            variants_matrix: transformedMatrix,
            price_tiers: cleanedPriceTiers
        };
    }).post(route('products.update', props.product.id), {
        preserveScroll: true
    });
};
</script>

<template>
    <Head :title="`Editar: ${form.name}`" />
    <AppLayout>
        <Breadcrumb :home="home" :model="items" class="!bg-transparent !p-0" />
        <div class="mt-4">
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Editar producto</h1>
        </div>

        <div class="mt-6 flex flex-col md:flex-row gap-6 items-start relative">
            <!-- Sidebar de Navegación -->
            <div class="w-full md:w-1/4 sticky top-24 z-10 hidden md:block">
                <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow-sm">
                    <h3 class="font-bold mb-4 text-gray-700 dark:text-gray-300">Secciones</h3>
                    <ul class="space-y-2">
                        <li>
                            <button type="button" @click="scrollTo('general')" class="text-left w-full px-3 py-2 rounded-md transition-colors" :class="activeSection === 'general' ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-400 font-medium' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-gray-700'">
                                Información General
                            </button>
                        </li>
                        <li>
                            <button type="button" @click="scrollTo('pricing')" class="text-left w-full px-3 py-2 rounded-md transition-colors" :class="activeSection === 'pricing' ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-400 font-medium' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-gray-700'">
                                Precios
                            </button>
                        </li>
                        <li>
                            <button type="button" @click="scrollTo('inventory')" class="text-left w-full px-3 py-2 rounded-md transition-colors" :class="activeSection === 'inventory' ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-400 font-medium' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-gray-700'">
                                Inventario y Variantes
                            </button>
                        </li>
                        <li>
                            <button type="button" @click="scrollTo('images')" class="text-left w-full px-3 py-2 rounded-md transition-colors" :class="activeSection === 'images' ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-400 font-medium' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-gray-700'">
                                Imágenes
                            </button>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Contenedor Principal -->
            <div class="w-full md:w-3/4">
                <form @submit.prevent="submit" class="space-y-6">
                    
                    <GeneralInfo 
                        :form="form" :categories="localCategories" :brands="localBrands" 
                        :providers="localProviders" :branches="branches"
                        @open-category="showCategoryModal = true" @open-brand="showBrandModal = true" @open-provider="showProviderModal = true"
                    />

                    <Pricing :form="form" />

                    <Inventory 
                        :form="form" :attributeDefinitions="attributeDefinitions"
                        @open-attributes="showAttributesModal = true"
                    />

                    <!-- IMPORTANTE: Aquí mandamos el 'product' completo para que el parcial extraiga las imágenes -->
                    <Images 
                        :form="form" 
                        :attributeDefinitions="attributeDefinitions" 
                        :product="product" 
                    />

                    <div class="flex justify-end sticky bottom-4 z-20">
                        <Button type="submit" label="Guardar cambios" icon="pi pi-save" severity="warning" size="large" :loading="form.processing" class="shadow-xl" />
                    </div>
                </form>
            </div>
        </div>

        <!-- Modales Independientes -->
        <ManageCategoriesModal v-model:visible="showCategoryModal" categoryType="product" @created="handleNewCategory" @updated="handleCategoryUpdate" @deleted="handleCategoryDelete" />
        <ManageBrandsModal v-model:visible="showBrandModal" @created="handleNewBrand" @updated="handleBrandUpdate" @deleted="handleBrandDelete" />
        <ManageProvidersModal v-model:visible="showProviderModal" @created="handleNewProvider" @updated="handleProviderUpdate" @deleted="handleProviderDelete" />
        <ManageAttributesModal v-if="form.category_id" v-model:visible="showAttributesModal" :category-id="form.category_id" />
        
        <ConfirmPopup group="price-tiers-delete" />
    </AppLayout>
</template>