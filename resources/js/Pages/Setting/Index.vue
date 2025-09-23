<script setup>
import { ref, watch, computed } from 'vue'; // AÑADIDO: computed
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

// Componentes de PrimeVue
import TabView from 'primevue/tabview';
import TabPanel from 'primevue/tabpanel';
import ToggleSwitch from 'primevue/toggleswitch';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import Textarea from 'primevue/textarea';
import Badge from 'primevue/badge';
import Chips from 'primevue/chips';
import FileUpload from 'primevue/fileupload';

const props = defineProps({
    settings: Object,
});

// -- Formulario Principal para ACTUALIZAR valores --
const initialFormValues = {};
Object.values(props.settings).flat().forEach(setting => {
    initialFormValues[setting.key] = setting.value;
});
const form = useForm({ settings: initialFormValues });
const submit = () => {
    form.post(route('settings.update'), { preserveScroll: true });
};

// --- Opciones para los Dropdowns ---
const levelOptions = ref([
    { label: 'Suscripción', value: 'subscription' },
    { label: 'Sucursal', value: 'branch' },
    { label: 'Usuario', value: 'user' },
]);
const typeOptions = ref([
    { label: 'Texto', value: 'text' },
    { label: 'Número', value: 'number' },
    { label: 'Booleano (Sí/No)', value: 'boolean' },
    { label: 'Lista', value: 'list' },
    { label: 'Selección', value: 'select' },
    { label: 'Archivo', value: 'file' },
]);

const moduleOptions = ref([
    'Control financiero', 'Cotizaciones', 'Gastos', 'Historial de ventas', 
    'Productos', 'Punto de venta', 'Servicios', 'Tienda en linea', 'Órdenes de servicio'
].sort((a, b) => a.localeCompare(b)));

// -- Lógica para CREAR una nueva definición --
const isCreateModalVisible = ref(false);
const createForm = useForm({
    name: '', key: '', description: '', module: null, 
    level: 'branch', type: 'text', default_value: '',
});
const submitCreateForm = () => {
    createForm.post(route('settings.store'), {
        preserveScroll: true,
        onSuccess: () => {
            isCreateModalVisible.value = false;
            createForm.reset();
        },
    });
};

// -- Lógica para EDITAR una definición existente --
const isEditModalVisible = ref(false);
const editForm = useForm({
    id: null, name: '', key: '', description: '', module: null,
    level: 'branch', type: 'text', default_value: '',
});

// AÑADIDO: Propiedad computada para manejar la visibilidad de AMBOS modales
const isModalVisible = computed({
  get: () => isCreateModalVisible.value || isEditModalVisible.value,
  set: (value) => {
    isCreateModalVisible.value = value;
    isEditModalVisible.value = value;
  }
});

const openEditModal = (setting) => {
    editForm.id = setting.id;
    editForm.name = setting.name;
    editForm.key = setting.key;
    editForm.description = setting.description;
    editForm.module = setting.module;
    editForm.level = setting.level;
    editForm.type = setting.type;
    
    if (['list', 'select'].includes(setting.type)) {
        try {
            const parsed = JSON.parse(setting.default_value);
            editForm.default_value = Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            editForm.default_value = [];
        }
    } else {
        editForm.default_value = setting.default_value;
    }
    
    isEditModalVisible.value = true;
};

const submitEditForm = () => {
    // CORRECCIÓN: Usar .put para actualizar, ya que la ruta es de tipo PUT
    editForm.put(route('settings.updateDefinition', editForm.id), {
        preserveScroll: true,
        onSuccess: () => isEditModalVisible.value = false,
    });
};

const resetDefaultValue = (formInstance, newType) => {
    if (['list', 'select'].includes(newType)) {
        if (!Array.isArray(formInstance.default_value)) formInstance.default_value = [];
    } else {
        if (Array.isArray(formInstance.default_value)) formInstance.default_value = '';
    }
};
watch(() => createForm.type, (newType) => resetDefaultValue(createForm, newType));
watch(() => editForm.type, (newType) => resetDefaultValue(editForm, newType));

// --- Funciones de ayuda para la UI ---
const getLevelBadgeSeverity = (level) => ({ subscription: 'info', branch: 'success', user: 'warning' }[level] || 'secondary');
const getLevelLabel = (level) => ({ subscription: 'Suscripción', branch: 'Sucursal', user: 'Usuario' }[level] || level);
</script>

<template>
    <Head title="Configuraciones" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8">
            <div class="max-w-4xl mx-auto">
                <header class="mb-6 flex justify-between space-x-3 items-center">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">Configuraciones</h1>
                         <p class="text-gray-500 dark:text-gray-400 mt-1">
                            Personaliza el comportamiento del sistema a nivel de
                            <span class="font-semibold text-sky-600 dark:text-sky-400">Suscripción</span>,
                            <span class="font-semibold text-emerald-600 dark:text-emerald-400">Sucursal</span> o
                            <span class="font-semibold text-amber-600 dark:text-amber-400">Usuario</span>.
                        </p>
                    </div>
                    <Button @click="isCreateModalVisible = true" label="Nueva Configuración" icon="pi pi-plus" severity="contrast" class="flex-shrink-0" />
                </header>
                
                <form @submit.prevent="submit">
                    <TabView>
                        <TabPanel v-for="(moduleSettings, moduleName) in settings" :key="moduleName" :header="moduleName">
                            <div class="p-4 space-y-8">
                                <div v-for="setting in moduleSettings" :key="setting.id" class="flex items-start justify-between">
                                    <div class="max-w-lg">
                                         <div class="flex items-center gap-x-2">
                                            <InputLabel :for="setting.key" :value="setting.name" class="font-semibold" />
                                            <Badge :value="getLevelLabel(setting.level)" :severity="getLevelBadgeSeverity(setting.level)" />
                                            <Button @click="openEditModal(setting)" icon="pi pi-pencil" class="h-6 w-6" text rounded />
                                        </div>
                                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ setting.description }}</p>
                                    </div>
                                    <div class="flex-shrink-0 ml-4 w-64">
                                        <ToggleSwitch v-if="setting.type === 'boolean'" :inputId="setting.key" v-model="form.settings[setting.key]" />
                                        <InputText v-if="setting.type === 'text'" :id="setting.key" v-model="form.settings[setting.key]" class="w-full" />
                                        <InputNumber v-if="setting.type === 'number'" :inputId="setting.key" v-model="form.settings[setting.key]" class="w-full" />
                                        <Chips v-if="setting.type === 'list'" :inputId="setting.key" v-model="form.settings[setting.key]" class="w-full" />
                                        <Dropdown v-if="setting.type === 'select'" v-model="form.settings[setting.key]" :options="setting.options" placeholder="Seleccionar" class="w-full" />
                                        <div v-if="setting.type === 'file'" class="w-full">
                                            <a v-if="typeof form.settings[setting.key] === 'string' && form.settings[setting.key]" :href="form.settings[setting.key]" target="_blank" class="text-sm text-sky-600 hover:underline mb-2 block">
                                                Ver archivo actual
                                            </a>
                                            <FileUpload mode="basic" :name="`settings[${setting.key}]`" @select="form.settings[setting.key] = $event.files[0]" :auto="true" customUpload chooseLabel="Elegir archivo" class="p-button-sm p-button-outlined" />
                                        </div>
                                        <InputError :message="form.errors[`settings.${setting.key}`]" class="mt-2" />
                                    </div>
                                </div>
                            </div>
                        </TabPanel>
                    </TabView>
                    <div class="mt-6 flex justify-end bg-white dark:bg-gray-800 p-4 rounded-b-lg shadow-md sticky bottom-0">
                        <Button type="submit" label="Guardar Cambios" icon="pi pi-check" :loading="form.processing" severity="warning" />
                    </div>
                </form>
            </div>
        </div>

        <!-- CAMBIO: El v-model ahora usa la propiedad computada 'isModalVisible' -->
        <Dialog v-model:visible="isModalVisible" modal :header="isEditModalVisible ? 'Editar Configuración' : 'Crear Nueva Configuración'" :style="{ width: '40rem' }">
            <form @submit.prevent="isEditModalVisible ? submitEditForm() : submitCreateForm()">
                <div class="p-fluid space-y-4">
                    <div class="field"><InputLabel value="Nombre" /><InputText v-model="(isEditModalVisible ? editForm : createForm).name" /><InputError :message="(isEditModalVisible ? editForm : createForm).errors.name" /></div>
                    <div class="field"><InputLabel value="Clave (Key)" /><InputText v-model="(isEditModalVisible ? editForm : createForm).key" /><InputError :message="(isEditModalVisible ? editForm : createForm).errors.key" /></div>
                    <div class="field"><InputLabel value="Descripción" /><Textarea v-model="(isEditModalVisible ? editForm : createForm).description" rows="3" /><InputError :message="(isEditModalVisible ? editForm : createForm).errors.description" /></div>
                    <div class="field"><InputLabel value="Módulo" /><Dropdown v-model="(isEditModalVisible ? editForm : createForm).module" :options="moduleOptions" /><InputError :message="(isEditModalVisible ? editForm : createForm).errors.module" /></div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="field"><InputLabel value="Nivel" /><Dropdown v-model="(isEditModalVisible ? editForm : createForm).level" :options="levelOptions" optionLabel="label" optionValue="value" /><InputError :message="(isEditModalVisible ? editForm : createForm).errors.level" /></div>
                        <div class="field"><InputLabel value="Tipo de Dato" /><Dropdown v-model="(isEditModalVisible ? editForm : createForm).type" :options="typeOptions" optionLabel="label" optionValue="value" /><InputError :message="(isEditModalVisible ? editForm : createForm).errors.type" /></div>
                    </div>
                    <div class="field">
                        <InputLabel :value="['list', 'select'].includes((isEditModalVisible ? editForm : createForm).type) ? 'Opciones (una por una)' : 'Valor por Defecto'" />
                        <Chips v-if="['list', 'select'].includes((isEditModalVisible ? editForm : createForm).type)" v-model="(isEditModalVisible ? editForm : createForm).default_value" />
                        <InputText v-else v-model="(isEditModalVisible ? editForm : createForm).default_value" />
                        <InputError :message="(isEditModalVisible ? editForm : createForm).errors.default_value" />
                    </div>
                </div>
            </form>
            <template #footer>
                <!-- CAMBIO: El botón de cancelar ahora usa la propiedad computada -->
                <Button label="Cancelar" icon="pi pi-times" @click="isModalVisible = false" text />
                <Button :label="isEditModalVisible ? 'Actualizar' : 'Crear'" icon="pi pi-check" @click="isEditModalVisible ? submitEditForm() : submitCreateForm()" :loading="(isEditModalVisible ? editForm : createForm).processing" />
            </template>
        </Dialog>
    </AppLayout>
</template>