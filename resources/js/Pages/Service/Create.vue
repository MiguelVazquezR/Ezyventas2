<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import ManageCategoriesModal from '@/Components/ManageCategoriesModal.vue';

const props = defineProps({
    categories: Array,
});

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([ { label: 'Catálogo de Servicios', url: route('services.index') }, { label: 'Crear Servicio' } ]);

const form = useForm({
    name: '',
    description: '',
    category_id: null,
    base_price: null,
    duration_estimate: '',
    show_online: false,
    image: null,
});

const localCategories = ref([...props.categories]);
const showCategoryModal = ref(false);

const handleNewCategory = (newCategory) => {
    localCategories.value.push(newCategory);
    form.category_id = newCategory.id;
};

// --- AÑADIDO ---
const handleCategoryUpdate = (updatedCategory) => {
    const index = localCategories.value.findIndex(c => c.id === updatedCategory.id);
    if (index !== -1) {
        localCategories.value[index] = updatedCategory;
    }
};

const handleCategoryDelete = (deletedCategoryId) => {
    localCategories.value = localCategories.value.filter(c => c.id !== deletedCategoryId);
    // Opcional: Si la categoría eliminada era la que estaba seleccionada, deseleccionarla.
    if (form.category_id === deletedCategoryId) {
        form.category_id = null;
    }
};
// --- FIN AÑADIDO ---

const submit = () => {
    form.post(route('services.store'));
};
</script>

<template>
    <AppLayout title="Crear servicio">
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        <div class="mt-4"><h1 class="text-2xl font-bold">Registrar Nuevo Servicio</h1></div>

        <form @submit.prevent="submit" class="mt-6 max-w-2xl mx-auto bg-white p-6 rounded-lg shadow-md">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="md:col-span-2">
                    <InputLabel for="name" value="Nombre del Servicio *" />
                    <InputText id="name" v-model="form.name" class="mt-1 w-full" />
                    <InputError :message="form.errors.name" class="mt-2" />
                </div>
                <div class="md:col-span-2">
                    <InputLabel for="description" value="Descripción" />
                    <Editor v-model="form.description" editorStyle="height: 150px" class="mt-1" />
                </div>
                <div>
                    <div class="flex justify-between items-center mb-1">
                        <InputLabel for="category" value="Categoría *" />
                        <!-- Botón ahora dice "Gestionar" para más claridad -->
                        <Button @click="showCategoryModal = true" label="Gestionar" icon="pi pi-cog" text size="small" />
                    </div>
                    <Select id="category" v-model="form.category_id" :options="localCategories" optionLabel="name" optionValue="id" placeholder="Selecciona una categoría" filter class="w-full" />
                    <InputError :message="form.errors.category_id" class="mt-2" />
                </div>
                 <div>
                    <InputLabel for="base_price" value="Precio Base *" />
                    <InputNumber id="base_price" v-model="form.base_price" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" />
                    <InputError :message="form.errors.base_price" class="mt-2" />
                </div>
                <div>
                    <InputLabel for="duration_estimate" value="Duración Estimada" />
                    <InputText id="duration_estimate" v-model="form.duration_estimate" class="mt-1 w-full" placeholder="Ej: 1-2 horas, 3 días hábiles" />
                </div>
                <!-- <div class="flex items-center gap-2 pt-5">
                    <ToggleSwitch v-model="form.show_online" inputId="show_online" />
                    <InputLabel for="show_online" value="Mostrar en Tienda en Línea" />
                </div> -->
                 <div class="md:col-span-2">
                    <InputLabel value="Imagen del Servicio" />
                    <FileUpload @select="form.image = $event.files[0]" :auto="true" :customUpload="true" accept="image/*" :show-upload-button="false" :show-cancel-button="false">
                        <template #empty><p>Arrastra y suelta una imagen aquí.</p></template>
                    </FileUpload>
                 </div>
            </div>
            <div class="flex justify-end mt-6">
                <Button type="submit" label="Guardar Servicio" :loading="form.processing" severity="warning" />
            </div>
        </form>
        
        <!-- AÑADIDOS: listeners para @updated y @deleted -->
        <ManageCategoriesModal 
            v-model:visible="showCategoryModal" 
            categoryType="service" 
            @created="handleNewCategory"
            @updated="handleCategoryUpdate"
            @deleted="handleCategoryDelete"
        />
    </AppLayout>
</template>
