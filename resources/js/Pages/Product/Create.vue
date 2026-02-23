<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ManageCategoriesModal from '@/Components/ManageCategoriesModal.vue';
import ManageBrandsModal from '@/Components/ManageBrandsModal.vue';
import ManageProvidersModal from '@/Components/ManageProvidersModal.vue';
import ManageAttributesModal from './Partials/ManageAttributesModal.vue';
import { useConfirm } from 'primevue/useconfirm';

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
    branch_ids: [props.current_branch_id], // Inicia seleccionando la sucursal actual
    category_id: null,
    brand_id: null,
    provider_id: null,
    cost_price: null,
    selling_price: null,
    price_tiers: [],
    product_type: 'simple',
    current_stock: null,
    min_stock: null,
    max_stock: null,
    measure_unit: 'Pza',
    variants_matrix: [],
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

// --- LÓGICA DE SCROLLSPY Y NAVEGACIÓN ---
const activeSection = ref('general');
let observer = null;
let isManualScrolling = false;

const scrollTo = (id) => {
    isManualScrolling = true;
    activeSection.value = id;
    const element = document.getElementById(id);
    if (element) {
        element.scrollIntoView({ behavior: 'smooth' });
        // Pausar el observer brevemente para que no parpadee mientras hace el scroll suave
        setTimeout(() => { isManualScrolling = false; }, 800);
    }
};

onMounted(() => {
    // Configuramos el Intersection Observer
    const options = {
        root: null,
        // Detectará la sección cuando cruce el 20% superior de la pantalla hasta la mitad
        rootMargin: '-20% 0px -50% 0px', 
        threshold: 0
    };

    observer = new IntersectionObserver((entries) => {
        if (isManualScrolling) return; // Ignorar si el usuario hizo clic en el menú
        
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                activeSection.value = entry.target.id;
            }
        });
    }, options);

    // Observar cada sección por su ID
    setTimeout(() => {
        const sections = ['general', 'pricing', 'inventory', 'images'];
        sections.forEach(id => {
            const el = document.getElementById(id);
            if (el) observer.observe(el);
        });
    }, 300);
});

onUnmounted(() => {
    if (observer) observer.disconnect();
});
// --- FIN LÓGICA DE SCROLLSPY ---

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
            
            <!-- Sidebar de Navegación -->
            <div class="w-full md:w-1/4 sticky top-24 z-10 hidden md:block">
                <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow-sm">
                    <h5 class="font-bold mb-4 text-gray-700 dark:text-gray-300">Secciones</h5>
                    <ul class="space-y-2">
                        <li>
                            <button type="button" @click="scrollTo('general')" class="text-left w-full px-3 py-2 rounded-md transition-colors" :class="activeSection === 'general' ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-400 font-medium' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-gray-700'">
                                Información general
                            </button>
                        </li>
                        <li>
                            <button type="button" @click="scrollTo('pricing')" class="text-left w-full px-3 py-2 rounded-md transition-colors" :class="activeSection === 'pricing' ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-400 font-medium' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-gray-700'">
                                Precios
                            </button>
                        </li>
                        <li>
                            <button type="button" @click="scrollTo('inventory')" class="text-left w-full px-3 py-2 rounded-md transition-colors" :class="activeSection === 'inventory' ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-400 font-medium' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-gray-700'">
                                Inventario y variantes
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

            <!-- Contenedor Principal de Parciales -->
            <div class="w-full md:w-3/4">
                <form @submit.prevent="submit" class="space-y-6">
                    
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

                    <Pricing :form="form" />

                    <Inventory 
                        :form="form" 
                        :attributeDefinitions="attributeDefinitions"
                        @open-attributes="showAttributesModal = true"
                    />

                    <Images 
                        :form="form" 
                        :attributeDefinitions="attributeDefinitions"
                    />

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