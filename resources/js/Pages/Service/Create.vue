<script setup>
import { ref, watch } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import ManageCategoriesModal from '@/Components/ManageCategoriesModal.vue';

const props = defineProps({
    categories: Array,
    current_branch_id: Number,
    branches: Array,
    serviceLimitReached: Boolean, // AÑADIDO: Prop para saber si llegó al límite
});

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([{ label: 'Catálogo de Servicios', url: route('services.index') }, { label: 'Crear Servicio' }]);

const form = useForm({
    name: '',
    description: '',
    category_id: null,
    base_price: null,
    duration_estimate: '',
    show_online: false,
    image: null,
    has_variants: false,
    branch_ids: [props.current_branch_id],
    variants: [],
});

const localCategories = ref([...props.categories]);
const showCategoryModal = ref(false);

const handleNewCategory = (newCategory) => {
    localCategories.value.push(newCategory);
    form.category_id = newCategory.id;
};

const handleCategoryUpdate = (updatedCategory) => {
    const index = localCategories.value.findIndex(c => c.id === updatedCategory.id);
    if (index !== -1) {
        localCategories.value[index] = updatedCategory;
    }
};

const handleCategoryDelete = (deletedCategoryId) => {
    localCategories.value = localCategories.value.filter(c => c.id !== deletedCategoryId);
    if (form.category_id === deletedCategoryId) {
        form.category_id = null;
    }
};

watch(() => form.has_variants, (newVal) => {
    if (newVal && form.variants.length === 0) {
        addVariant();
    }
});

const addVariant = () => {
    form.variants.push({
        name: '',
        price: null,
        duration_estimate: ''
    });
};

const removeVariant = (index) => {
    form.variants.splice(index, 1);
    if (form.variants.length === 0) {
        form.has_variants = false;
    }
};

const submit = () => {
    if (form.has_variants) {
        form.base_price = 0;
        form.duration_estimate = null;
    } else {
        form.variants = [];
    }

    form.post(route('services.store'));
};
</script>

<template>
    <AppLayout title="Crear servicio">
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        <div class="mt-4">
            <h1 class="text-2xl font-bold">Registrar nuevo servicio</h1>
        </div>

        <!-- VISTA DE LÍMITE ALCANZADO -->
        <div v-if="serviceLimitReached" class="mt-6 max-w-3xl mx-auto bg-white dark:bg-gray-800 p-8 rounded-lg shadow-md text-center">
            <div class="mb-6 flex justify-center">
                <div class="bg-gray-100 dark:bg-gray-700 w-24 h-24 rounded-full flex items-center justify-center">
                    <i class="pi pi-lock !text-5xl text-gray-400 dark:text-gray-500"></i>
                </div>
            </div>
            <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-200 mb-3">Límite de servicios alcanzado</h2>
            <p class="text-gray-600 dark:text-gray-400 mb-8 max-w-lg mx-auto leading-relaxed">
                Has alcanzado la cantidad máxima permitida de servicios o variantes en tu plan actual. Para seguir ampliando tu catálogo y agregar nuevos conceptos, necesitas mejorar tu suscripción.
            </p>
            <div class="flex flex-col sm:flex-row justify-center gap-4">
                <Link :href="route('subscription.manage')">
                    <Button label="Mejorar suscripción" icon="pi pi-arrow-up" size="large" severity="primary" class="w-full sm:w-auto" />
                </Link>
                <Link :href="route('services.index')">
                    <Button label="Volver al catálogo" icon="pi pi-arrow-left" size="large" severity="secondary" outlined class="w-full sm:w-auto" />
                </Link>
            </div>
        </div>

        <!-- FORMULARIO NORMAL -->
        <form v-else @submit.prevent="submit"
            class="mt-6 max-w-3xl mx-auto bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Información General -->
                <div class="md:col-span-2">
                    <InputLabel for="name" value="Nombre del Servicio *" />
                    <InputText id="name" v-model="form.name" class="mt-1 w-full" placeholder="Ej: Cambio de Pantalla" />
                    <InputError :message="form.errors.name" class="mt-2" />
                </div>

                <div>
                    <InputLabel for="branch_ids" value="Disponible en sucursales:" />
                    <MultiSelect id="branch_ids" v-model="form.branch_ids" :options="branches" optionLabel="name"
                        optionValue="id" placeholder="Selecciona las sucursales" class="w-full mt-1" display="chip" />
                </div>

                <div class="md:col-span-2">
                    <div class="flex justify-between items-center mb-1">
                        <InputLabel for="category" value="Categoría *" />
                        <Button @click="showCategoryModal = true" label="Gestionar" icon="pi pi-cog" text
                            size="small" />
                    </div>
                    <Select id="category" v-model="form.category_id" :options="localCategories" optionLabel="name"
                        optionValue="id" placeholder="Selecciona una categoría" filter class="w-full" />
                    <InputError :message="form.errors.category_id" class="mt-2" />
                </div>

                <div class="md:col-span-2">
                    <InputLabel for="description" value="Descripción" />
                    <Editor v-model="form.description" editorStyle="height: 150px" class="mt-1" />
                </div>

                <!-- SWITCH DE VARIANTES -->
                <div class="md:col-span-2 mt-4">
                    <div
                        class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-lg border border-blue-100 dark:border-blue-800 flex items-start gap-4">
                        <div class="pt-1">
                            <ToggleSwitch v-model="form.has_variants" inputId="has_variants" />
                        </div>
                        <div>
                            <InputLabel for="has_variants"
                                value="Este servicio tiene múltiples variantes (ej. por modelo, calidad, cilindraje)"
                                class="!font-bold !text-blue-800 dark:!text-blue-200 cursor-pointer" />
                            <p class="text-sm text-blue-600 dark:text-blue-400 mt-1">
                                Activa esto si el precio o la duración cambian dependiendo de qué dispositivo/equipo se
                                esté reparando (Ej: Pantalla OLED vs Original).
                            </p>
                        </div>
                    </div>
                </div>

                <!-- SECCIÓN: SIN VARIANTES (Precio Único) -->
                <template v-if="!form.has_variants">
                    <div>
                        <InputLabel for="base_price" value="Precio Base *" />
                        <InputNumber id="base_price" v-model="form.base_price" mode="currency" currency="MXN"
                            locale="es-MX" class="w-full mt-1" placeholder="$0.00" />
                        <InputError :message="form.errors.base_price" class="mt-2" />
                    </div>
                    <div>
                        <InputLabel for="duration_estimate" value="Duración Estimada" />
                        <InputText id="duration_estimate" v-model="form.duration_estimate" class="mt-1 w-full"
                            placeholder="Ej: 1-2 horas, 3 días hábiles" />
                    </div>
                </template>

                <!-- SECCIÓN: CON VARIANTES (Precios Dinámicos) -->
                <div v-else
                    class="md:col-span-2 bg-gray-50 dark:bg-gray-900 p-4 rounded-lg border dark:border-gray-700">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-bold text-gray-700 dark:text-gray-200">Variantes del Servicio</h3>
                        <Button @click="addVariant" label="Añadir variante" icon="pi pi-plus" size="small"
                            severity="secondary" outlined />
                    </div>

                    <div class="flex flex-col gap-3">
                        <div v-for="(variant, index) in form.variants" :key="index"
                            class="flex flex-col md:flex-row gap-2 items-start md:items-center bg-white dark:bg-gray-800 p-3 rounded shadow-sm border dark:border-gray-700">
                            <div class="w-full md:w-5/12">
                                <InputLabel :value="'Nombre de la Variante *'" class="text-xs !mb-1 md:hidden" />
                                <InputText v-model="variant.name" placeholder="Ej: iPhone 11 - OLED"
                                    class="w-full text-sm" required />
                                <InputError :message="form.errors[`variants.${index}.name`]" class="mt-1" />
                            </div>

                            <div class="w-full md:w-3/12">
                                <InputLabel :value="'Precio *'" class="text-xs !mb-1 md:hidden" />
                                <InputNumber v-model="variant.price" mode="currency" currency="MXN" locale="es-MX"
                                    placeholder="$0.00" class="w-full text-sm" inputClass="!w-full" required />
                                <InputError :message="form.errors[`variants.${index}.price`]" class="mt-1" />
                            </div>

                            <div class="w-full md:w-3/12">
                                <InputLabel :value="'Duración (Opcional)'" class="text-xs !mb-1 md:hidden" />
                                <InputText v-model="variant.duration_estimate" placeholder="Ej: 2 hrs"
                                    class="w-full text-sm" />
                            </div>

                            <div class="w-full md:w-1/12 flex justify-end">
                                <Button icon="pi pi-trash" severity="danger" text rounded @click="removeVariant(index)"
                                    v-tooltip.top="'Eliminar'" />
                            </div>
                        </div>
                    </div>
                    <InputError :message="form.errors.variants" class="mt-2" />
                </div>

                <!-- Imagen -->
                <div class="md:col-span-2 mt-4">
                    <InputLabel value="Imagen del servicio (Opcional)" />
                    <FileUpload @select="form.image = $event.files[0]" :auto="true" :customUpload="true"
                        accept="image/*" :show-upload-button="false" :show-cancel-button="false">
                        <template #empty>
                            <p>Arrastra y suelta una imagen aquí o haz clic para seleccionar.</p>
                        </template>
                    </FileUpload>
                </div>
            </div>

            <div class="flex justify-end mt-8 pt-4 border-t dark:border-gray-700">
                <Button type="submit" label="Guardar servicio" icon="pi pi-save" :loading="form.processing"
                    severity="primary" class="w-full md:w-auto" />
            </div>
        </form>

        <ManageCategoriesModal v-model:visible="showCategoryModal" categoryType="service" @created="handleNewCategory"
            @updated="handleCategoryUpdate" @deleted="handleCategoryDelete" />
    </AppLayout>
</template>