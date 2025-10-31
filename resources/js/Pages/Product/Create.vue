<script setup>
import { ref, computed, nextTick, markRaw, watch } from 'vue';
import { Head, useForm, router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ManageCategoriesModal from '@/Components/ManageCategoriesModal.vue';
import CreateBrandModal from './Partials/CreateBrandModal.vue';
import CreateProviderModal from './Partials/CreateProviderModal.vue';
import ManageAttributesModal from './Partials/ManageAttributesModal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { useConfirm } from 'primevue/useconfirm';

const props = defineProps({
    categories: Array,
    brands: Array,
    providers: Array,
    attributeDefinitions: Array,
    productLimit: Number,
    productUsage: Number,
});

const confirm = useConfirm();

// --- AÑADIDO: Lógica para verificar si se alcanzó el límite ---
const limitReached = computed(() => {
    if (props.productLimit === -1) return false;
    return props.productUsage >= props.productLimit;
});

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const items = ref([
    { label: 'Productos', url: route('products.index') },
    { label: 'Crear producto' }
]);

const form = useForm({
    name: '',
    description: '',
    sku: '',
    category_id: null,
    brand_id: null,
    cost_price: null,
    provider_id: null,
    selling_price: null,
    price_tiers: [],
    product_type: 'simple',
    current_stock: null,
    min_stock: null,
    max_stock: null,
    measure_unit: 'pieza',
    general_images: [],
    variant_images: {},
    variant_attributes: [],
    selected_variant_options: {},
    variants_matrix: [],
    show_online: false,
    online_price: null,
    requires_shipping: false,
    weight: null,
    length: null,
    width: null,
    height: null,
    tags: [],
});

const productTypeOptions = ref([
    { label: 'Producto Simple', value: 'simple' },
    { label: 'Producto con Variantes', value: 'variant' }
]);

const variantImagePreviews = ref({});
const selectedVariants = ref([]);

const availableAttributes = computed(() => {
    if (!form.category_id) return [];
    return props.attributeDefinitions.filter(attr => attr.category_id === form.category_id);
});

const imageRequiringAttributes = computed(() => {
    if (form.product_type !== 'variant') return [];
    return availableAttributes.value.filter(
        attr => form.variant_attributes.includes(attr.id) && attr.requires_image
    );
});

const variantCombinations = computed(() => {
    const canGenerate = form.product_type === 'variant' &&
        form.variant_attributes.length > 0 &&
        form.variant_attributes.every(id => form.selected_variant_options[id] && form.selected_variant_options[id].length > 0);

    if (!canGenerate) return [];

    const selectedAttrsWithOptions = props.attributeDefinitions
        .filter(attr => form.variant_attributes.includes(attr.id))
        .map(attr => ({
            ...attr,
            options: form.selected_variant_options[attr.id].map(val => ({ value: val }))
        }))
        .filter(attr => attr.options.length > 0);

    if (selectedAttrsWithOptions.length === 0) return [];

    const generate = (attrs, index = 0, current = {}) => {
        if (index === attrs.length) {
            const combination = { ...current, selected: true, sku_suffix: '', current_stock: 0, min_stock: 0, max_stock: 0, selling_price: form.selling_price };
            combination.row_id = Object.values(combination).join('-');
            return [combination];
        }
        let results = [];
        const attr = attrs[index];
        attr.options.forEach(option => {
            let next = { ...current };
            next[attr.name] = option.value;
            results = results.concat(generate(attrs, index + 1, next));
        });
        return results;
    };
    return generate(selectedAttrsWithOptions);
});

watch(() => form.category_id, (newCategoryId, oldCategoryId) => {
    if (newCategoryId !== oldCategoryId) {
        form.variant_attributes = [];
        form.selected_variant_options = {};
        form.variants_matrix = [];
        selectedVariants.value = [];
        form.variant_images = {};
        variantImagePreviews.value = {};
    }
});

watch(() => form.variant_attributes, (newAttributeIds) => {
    const newOptions = {};
    newAttributeIds.forEach(id => {
        newOptions[id] = form.selected_variant_options[id] || [];
    });
    form.selected_variant_options = newOptions;
}, { deep: true });


watch(variantCombinations, (newCombinations) => {
    selectedVariants.value = [...newCombinations];
}, { deep: true });

const submit = () => {
    const matrixWithSelection = variantCombinations.value.map(combo => ({
        ...combo,
        selected: selectedVariants.value.some(sel => sel.row_id === combo.row_id)
    }));
    form.variants_matrix = matrixWithSelection;

    form.post(route('products.store'), {
        preserveScroll: true,
    });
};

const onSelectGeneralImages = (event) => form.general_images = event.files;
const onRemoveGeneralImage = (event) => form.general_images = form.general_images.filter(img => img.objectURL !== event.file.objectURL);
const onSelectVariantImage = (event, optionValue) => {
    const file = event.files[0];
    form.variant_images[optionValue] = file;
    variantImagePreviews.value[optionValue] = URL.createObjectURL(file);
};
const onRemoveVariantImage = (optionValue) => {
    delete form.variant_images[optionValue];
    URL.revokeObjectURL(variantImagePreviews.value[optionValue]);
    delete variantImagePreviews.value[optionValue];
};

const localCategories = ref([...props.categories]);
const localBrands = ref(JSON.parse(JSON.stringify(props.brands)));
const localProviders = ref([...props.providers]);
const showCategoryModal = ref(false);
const showBrandModal = ref(false);
const showProviderModal = ref(false);
const showAttributesModal = ref(false);

const handleNewCategory = (newCategory) => {
    localCategories.value.push(markRaw(newCategory));
    nextTick(() => { form.category_id = newCategory.id; });
    showCategoryModal.value = false;
};

const handleCategoryUpdate = (updatedCategory) => {
    const index = localCategories.value.findIndex(c => c.id === updatedCategory.id);
    if (index !== -1) {
        localCategories.value[index] = markRaw(updatedCategory);
    }
};

const handleCategoryDelete = (deletedCategoryId) => {
    localCategories.value = localCategories.value.filter(c => c.id !== deletedCategoryId);
    if (form.category_id === deletedCategoryId) {
        form.category_id = null;
    }
};

const handleNewBrand = (newBrand) => {
    const myBrandsGroup = localBrands.value.find(g => g.label === 'Mis Marcas');
    if (myBrandsGroup) { myBrandsGroup.items.push(markRaw(newBrand)); }
    nextTick(() => { form.brand_id = newBrand.id; });
};
const handleNewProvider = (newProvider) => {
    localProviders.value.push(markRaw(newProvider));
    nextTick(() => { form.provider_id = newProvider.id; });
};

const refreshAttributes = () => {
    router.reload({
        only: ['attributeDefinitions'],
        preserveState: true,
        preserveScroll: true,
    });
};

// --- Funciones para manejar niveles de precio ---
const addPriceTier = () => {
    form.price_tiers.push({
        min_quantity: null,
        price: null
    });
};

const removePriceTier = (index) => {
    form.price_tiers.splice(index, 1);
};

const confirmRemoveItem = (event, index) => {
    confirm.require({
        target: event.currentTarget,
        message: '¿Estás seguro de que quieres eliminar este elemento?',
        group: 'price-tiers-delete',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Sí',
        rejectLabel: 'No',
        accept: () => {
            removePriceTier(index);
        }
    });
};
</script>

<template>

    <Head title="Agregar nuevo producto" />
    <AppLayout>
        <Breadcrumb :home="home" :model="items" class="!bg-transparent" />
        <div class="p-4 md:p-6 lg:p-8">
            <!-- AÑADIDO: Mensaje cuando se alcanza el límite -->
            <div v-if="limitReached"
                class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 max-w-2xl mx-auto text-center">
                <i class="pi pi-exclamation-triangle !text-6xl text-amber-500 mb-4"></i>
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200 mb-2">Límite de Productos Alcanzado</h1>
                <p class="text-gray-600 dark:text-gray-300 mb-6">
                    Has alcanzado el límite de <strong>{{ productLimit }} productos</strong> permitido por tu plan
                    actual. Para agregar más productos, por favor mejora tu plan.
                </p>
                <div class="flex justify-center items-center gap-4">
                    <Link :href="route('products.index')">
                    <Button label="Volver a Productos" severity="secondary" outlined />
                    </Link>
                    <a :href="route('subscription.manage')" target="_blank" rel="noopener noreferrer">
                        <Button label="Mejorar mi plan" icon="pi pi-arrow-up" />
                    </a>
                </div>
            </div>

            <!-- Formulario de creación original -->
            <div v-else class="max-w-4xl mx-auto">
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200 mb-6">Agregar nuevo producto</h1>
                <form @submit.prevent="submit">
                    <!-- Sección de Información General -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                        <h2
                            class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4 text-gray-800 dark:text-gray-200">
                            Información general
                        </h2>
                        <div class="grid grid-cols-1 gap-6">
                            <div>
                                <InputLabel for="name" value="Nombre del producto*" />
                                <InputText id="name" v-model="form.name" class="mt-1 w-full" />
                                <InputError class="mt-2" :message="form.errors.name" />
                            </div>
                            <div>
                                <InputLabel value="Descripción" />
                                <Editor v-model="form.description" editorStyle="height: 150px" class="mt-1" />
                                <InputError class="mt-2" :message="form.errors.description" />
                            </div>
                            <div>
                                <InputLabel for="sku" value="SKU (Código de barras)" />
                                <InputText id="sku" v-model="form.sku" class="mt-1 w-full" />
                                <InputError class="mt-2" :message="form.errors.sku" />
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <div class="flex justify-between items-center mb-1">
                                        <InputLabel for="category_id" value="Categoría*" />
                                        <Button @click="showCategoryModal = true" icon="pi pi-cog" label="Gestionar"
                                            text size="small" />
                                    </div>
                                    <Select v-model="form.category_id" id="category_id" size="large"
                                        :options="localCategories" filter optionLabel="name" optionValue="id"
                                        placeholder="Selecciona una categoría" class="w-full" />
                                    <InputError class="mt-2" :message="form.errors.category_id" />
                                </div>
                                <div>
                                    <div class="flex justify-between items-center mb-1">
                                        <InputLabel for="brand_id" value="Marca" />
                                        <Button @click="showBrandModal = true" icon="pi pi-plus" label="Nueva" text
                                            size="small" />
                                    </div>
                                    <Select v-model="form.brand_id" id="brand_id" size="large" :options="localBrands"
                                        filter optionLabel="name" optionValue="id" placeholder="Selecciona una marca"
                                        class="w-full" optionGroupLabel="label" optionGroupChildren="items">
                                        <template #optiongroup="{ option }">
                                            <div
                                                class="flex items-center font-bold px-2 py-1 bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200">
                                                {{ option.label }}
                                            </div>
                                        </template>
                                        <template #option="{ option }">
                                            <div class="px-2 py-1">{{ option.name }}</div>
                                        </template>
                                    </Select>
                                    <InputError class="mt-2" :message="form.errors.brand_id" />
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Sección de Precios (MODIFICADA) -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                        <h2
                            class="text-lg font-semibold border-b border-gray-200 dark:border-gray-700 pb-3 mb-4 text-gray-800 dark:text-gray-200">
                            Precios
                        </h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="mt-3">
                                <InputLabel for="cost_price" value="Precio de compra" />
                                <InputNumber v-model="form.cost_price" id="cost_price" mode="currency" currency="MXN"
                                    locale="es-MX" class="w-full mt-1" />
                                <InputError class="mt-2" :message="form.errors.cost_price" />
                            </div>
                            <div>
                                <div class="flex justify-between items-center mb-1">
                                    <InputLabel for="provider_id" value="Proveedor" />
                                    <Button @click="showProviderModal = true" icon="pi pi-plus" label="Nuevo" text
                                        size="small" />
                                </div>
                                <Select v-model="form.provider_id" id="provider_id" size="large"
                                    :options="localProviders" filter optionLabel="name" optionValue="id"
                                    placeholder="Selecciona un proveedor" class="w-full" />
                                <InputError class="mt-2" :message="form.errors.provider_id" />
                            </div>
                            <div class="md:col-span-2">
                                <InputLabel for="selling_price" value="Precio de venta al público (1 Pieza)*" />
                                <InputNumber v-model="form.selling_price" id="selling_price" mode="currency"
                                    currency="MXN" locale="es-MX" class="w-full mt-1" />
                                <InputError class="mt-2" :message="form.errors.selling_price" />
                            </div>
                        </div>

                        <!-- --- INICIO: SECCIÓN DE PRECIOS POR MAYOREO --- -->
                        <div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
                            <h5 class="font-semibold text-gray-800 dark:text-gray-200 m-0">
                                Precios de mayoreo (opcional)
                            </h5>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                                Define precios especiales para compras de mayor volumen. El precio de 1 pieza se toma
                                del campo de arriba.
                            </p>

                            <div v-if="form.price_tiers.length > 0" class="space-y-4">
                                <div v-for="(tier, index) in form.price_tiers" :key="index"
                                    class="flex items-end gap-4 p-4 rounded-md bg-gray-50 dark:bg-gray-700/50 border dark:border-gray-700">
                                    <div class="flex-1">
                                        <InputLabel :for="`tier_min_${index}`" value="A partir de (cant.)" />
                                        <InputNumber v-model="tier.min_quantity" :id="`tier_min_${index}`"
                                            class="w-full mt-1" :min="2" placeholder="Ej: 6" />
                                        <InputError class="mt-1"
                                            :message="form.errors[`price_tiers.${index}.min_quantity`]" />
                                    </div>
                                    <div class="flex-1">
                                        <InputLabel :for="`tier_price_${index}`" value="Precio unitario" />
                                        <InputNumber v-model="tier.price" :id="`tier_price_${index}`" mode="currency"
                                            currency="MXN" locale="es-MX" class="w-full mt-1" />
                                        <InputError class="mt-1" :message="form.errors[`price_tiers.${index}.price`]" />
                                    </div>
                                    <Button @click="confirmRemoveItem($event, index)" icon="pi pi-trash"
                                        severity="danger" text rounded v-tooltip.bottom="'Eliminar nivel'" />
                                </div>
                            </div>
                            <InputError class="mt-2" :message="form.errors.price_tiers" />

                            <Button @click="addPriceTier" label="Añadir nivel de precio" icon="pi pi-plus"
                                severity="secondary" outlined class="mt-4" />
                        </div>
                        <!-- --- FIN: SECCIÓN DE PRECIOS POR MAYOREO --- -->

                    </div>
                    <!-- Sección de Inventario y Variantes -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                        <div
                            class="flex justify-between items-center border-b border-gray-200 dark:border-gray-700 pb-3 mb-4">
                            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-200 m-0">
                                Inventario y variantes
                            </h2>
                            <Button v-if="form.category_id" icon="pi pi-cog" text rounded
                                v-tooltip.left="'Gestionar variantes de la categoría'"
                                @click="showAttributesModal = true" />
                        </div>
                        <div>
                            <InputLabel value="Tipo de producto" class="mb-2" />
                            <SelectButton v-model="form.product_type" :options="productTypeOptions" optionLabel="label"
                                optionValue="value" :allowEmpty="false" />
                        </div>
                        <div v-if="form.product_type === 'simple'" class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <InputLabel for="current_stock_simple" value="Stock actual" />
                                <InputNumber v-model="form.current_stock" id="current_stock_simple"
                                    class="w-full mt-1" />
                                <InputError class="mt-2" :message="form.errors.current_stock" />
                            </div>
                            <div>
                                <InputLabel for="min_stock_simple" value="Stock mínimo" />
                                <InputNumber v-model="form.min_stock" id="min_stock_simple" class="w-full mt-1" />
                            </div>
                            <div>
                                <InputLabel for="max_stock_simple" value="Stock máximo" />
                                <InputNumber v-model="form.max_stock" id="max_stock_simple" class="w-full mt-1" />
                            </div>
                        </div>
                        <div v-if="form.product_type === 'variant' && form.category_id" class="mt-6 space-y-4">
                            <div>
                                <InputLabel for="variant_attributes" value="Atributos para variantes" />
                                <MultiSelect v-model="form.variant_attributes" id="variant_attributes"
                                    :options="availableAttributes" optionLabel="name" optionValue="id"
                                    placeholder="Selecciona atributos" class="w-full mt-1" />
                            </div>
                            <div v-if="form.variant_attributes.length > 0"
                                class="mt-4 space-y-4 p-4 border dark:border-gray-700 rounded-md bg-gray-50 dark:bg-gray-800/50">
                                <h4 class="font-medium text-gray-700 dark:text-gray-300">Selecciona las opciones a usar:
                                </h4>
                                <div v-for="attrId in form.variant_attributes" :key="attrId">
                                    <template v-if="availableAttributes.find(a => a.id === attrId)">
                                        <InputLabel :value="availableAttributes.find(a => a.id === attrId).name"
                                            class="mb-1" />
                                        <MultiSelect v-model="form.selected_variant_options[attrId]"
                                            :options="availableAttributes.find(a => a.id === attrId).options"
                                            optionLabel="value" optionValue="value"
                                            :placeholder="`Elige ${availableAttributes.find(a => a.id === attrId).name}`"
                                            class="w-full" />
                                    </template>
                                </div>
                            </div>
                        </div>
                        <div v-if="variantCombinations.length > 0" class="mt-6">
                            <h3 class="font-semibold text-gray-800 dark:text-gray-200">Gestión de variantes</h3>
                            <DataTable :value="variantCombinations" v-model:selection="selectedVariants"
                                dataKey="row_id" class="p-datatable-sm mt-2">
                                <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>
                                <Column
                                    v-for="attr in availableAttributes.filter(a => form.variant_attributes.includes(a.id))"
                                    :key="attr.id" :field="attr.name" :header="attr.name"></Column>
                                <Column header="Stock Actual"><template #body="{ data }">
                                        <InputNumber v-model="data.current_stock" inputClass="w-20" />
                                    </template>
                                </Column>
                                <Column header="Stock Mínimo"><template #body="{ data }">
                                        <InputNumber v-model="data.min_stock" inputClass="w-20" />
                                    </template></Column>
                                <Column header="Precio Venta"><template #body="{ data }">
                                        <InputNumber v-model="data.selling_price" mode="currency" currency="MXN"
                                            locale="es-MX" inputClass="w-28" />
                                    </template>
                                </Column>
                            </DataTable>
                        </div>
                        <div v-if="form.product_type === 'variant' && !form.category_id"
                            class="mt-6 p-4 bg-yellow-50 dark:bg-yellow-900/20 text-yellow-700 dark:text-yellow-300 rounded-md">
                            Por favor, selecciona una categoría para gestionar sus variantes.
                        </div>
                        <div class="mt-6">
                            <Tabs value="0">
                                <TabList>
                                    <Tab value="0">Imágenes generales</Tab>
                                    <Tab value="1">Imágenes por variante</Tab>
                                </TabList>
                                <TabPanels>
                                    <TabPanel value="0">
                                        <FileUpload name="general_images[]" @select="onSelectGeneralImages"
                                            @remove="onRemoveGeneralImage" :multiple="true" :show-upload-button="false"
                                            accept="image/*" :maxFileSize="5000000">
                                            <template #empty>
                                                <p>Arrastra y suelta hasta 5 imágenes generales del producto.</p>
                                            </template>
                                        </FileUpload>
                                        <InputError class="mt-2" :message="form.errors.general_images" />
                                    </TabPanel>
                                    <TabPanel value="1" :disabled="imageRequiringAttributes.length === 0">
                                        <div v-if="imageRequiringAttributes.length > 0" class="space-y-4 mt-5">
                                            <div v-for="attr in imageRequiringAttributes" :key="attr.id">
                                                <h4 class="font-medium text-gray-700 dark:text-gray-300 mb-2">Imágenes
                                                    para
                                                    {{ attr.name }}</h4>
                                                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                                    <template v-for="option in attr.options" :key="option.id">
                                                        <div v-if="form.selected_variant_options[attr.id] && form.selected_variant_options[attr.id].includes(option.value)"
                                                            class="text-center">
                                                            <InputLabel :value="option.value" class="text-sm" />
                                                            <div class="mt-1 flex flex-col items-center gap-2">
                                                                <div class="relative w-20 h-20">
                                                                    <img v-if="variantImagePreviews[option.value]"
                                                                        :src="variantImagePreviews[option.value]"
                                                                        class="w-20 h-20 object-cover rounded-md border">
                                                                    <div v-else
                                                                        class="w-20 h-20 bg-gray-100 dark:bg-gray-700 rounded-md flex items-center justify-center text-gray-400">
                                                                        <i class="pi pi-image text-2xl"></i>
                                                                    </div>
                                                                    <Button v-if="variantImagePreviews[option.value]"
                                                                        @click="onRemoveVariantImage(option.value)"
                                                                        icon="pi pi-times" rounded text
                                                                        severity="danger"
                                                                        class="!absolute !top-[-8px] !right-[-8px] bg-white dark:bg-gray-800"
                                                                        v-tooltip.bottom="'Eliminar imagen'" />
                                                                </div>
                                                                <FileUpload v-if="!variantImagePreviews[option.value]"
                                                                    :show-upload-button="false" mode="basic"
                                                                    :name="`variant_images[${option.value}]`"
                                                                    accept="image/*" :maxFileSize="1000000" :auto="true"
                                                                    :customUpload="true"
                                                                    @uploader="onSelectVariantImage($event, option.value)"
                                                                    chooseLabel="Elegir" class="p-button-sm !w-20" />
                                                            </div>
                                                        </div>
                                                    </template>
                                                </div>
                                            </div>
                                        </div>
                                        <div v-else class="text-center text-gray-500 dark:text-gray-400 p-4 text-sm">
                                            <p class="m-0">Ninguna de las variantes seleccionadas requiere una imagen
                                                específica.</p>
                                        </div>
                                        <InputError class="mt-2" :message="form.errors.variant_images" />
                                    </TabPanel>
                                </TabPanels>
                            </Tabs>
                        </div>
                        <div class="mt-6">
                            <InputLabel for="measure_unit" value="Unidad de medida" />
                            <Select v-model="form.measure_unit" id="measure_unit" size="large"
                                :options="['pieza', 'kg', 'g', 'litro', 'ml', 'caja', 'paquete']"
                                class="w-full md:w-1/3 mt-1" />
                            <InputError class="mt-2" :message="form.errors.measure_unit" />
                        </div>
                    </div>
                    <!-- Sección Tienda en Línea -->
                    <!-- <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                        <div class="flex items-center justify-between">
                            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-200">Opciones para tienda en
                                línea</h2>
                            <ToggleSwitch v-model="form.show_online" />
                        </div>
                        <div v-if="form.show_online"
                            class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700 grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <InputLabel for="online_price" value="Precio de venta en línea" />
                                <InputNumber v-model="form.online_price" id="online_price" mode="currency"
                                    currency="MXN" locale="es-MX" class="w-full mt-1"
                                    placeholder="Usar precio de venta general" />
                            </div>
                            <div class="flex items-center gap-2 pt-5">
                                <Checkbox v-model="form.requires_shipping" :binary="true" inputId="requires_shipping" />
                                <InputLabel for="requires_shipping" value="Requiere envío físico" />
                            </div>
                            <div v-if="form.requires_shipping"
                                class="md:col-span-2 grid grid-cols-2 md:grid-cols-4 gap-4">
                                <div>
                                    <InputLabel for="weight" value="Peso (kg)" />
                                    <InputNumber v-model="form.weight" id="weight" mode="decimal" :minFractionDigits="2"
                                        :maxFractionDigits="3" class="w-full mt-1" />
                                    <InputError class="mt-2" :message="form.errors.weight" />
                                </div>
                                <div>
                                    <InputLabel for="length" value="Largo (cm)" />
                                    <InputNumber v-model="form.length" id="length" class="w-full mt-1" />
                                </div>
                                <div>
                                    <InputLabel for="width" value="Ancho (cm)" />
                                    <InputNumber v-model="form.width" id="width" class="w-full mt-1" />
                                </div>
                                <div>
                                    <InputLabel for="height" value="Alto (cm)" />
                                    <InputNumber v-model="form.height" id="height" class="w-full mt-1" />
                                </div>
                            </div>
                        </div>
                    </div> -->
                    <div class="flex justify-end sticky bottom-4">
                        <Button type="submit" label="Crear producto" icon="pi pi-check" severity="warning"
                            :loading="form.processing" />
                    </div>
                </form>
            </div>
        </div>
        <!-- Modales -->
        <ManageCategoriesModal v-model:visible="showCategoryModal" categoryType="product" @created="handleNewCategory"
            @updated="handleCategoryUpdate" @deleted="handleCategoryDelete" />
        <CreateBrandModal v-model:visible="showBrandModal" @created="handleNewBrand" />
        <CreateProviderModal v-model:visible="showProviderModal" @created="handleNewProvider" />
        <ManageAttributesModal v-if="form.category_id" v-model:visible="showAttributesModal"
            :category-id="form.category_id" @updated="refreshAttributes" />

        <ConfirmPopup group="price-tiers-delete" />
    </AppLayout>
</template>