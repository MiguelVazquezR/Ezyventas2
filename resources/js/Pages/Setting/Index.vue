<script setup>
import { ref, watch, computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    settings: Object,
});

// -- Formulario Principal para ACTUALIZAR valores --
const initializeForm = () => {
    const values = {};
    Object.values(props.settings).flat().forEach(setting => {
        values[setting.key] = setting.value;
    });
    return values;
};
const form = useForm({ settings: initializeForm() });

// AÑADIDO: Watcher para refrescar los datos del formulario cuando las props cambian tras un guardado exitoso.
// Esto asegura que la UI se actualice sin necesidad de recargar la página manualmente.
watch(() => props.settings, () => {
    form.defaults({ settings: initializeForm() });
    form.reset();
}, { deep: true });

// AÑADIDO: Estado para gestionar la UI de los archivos seleccionados temporalmente.
const selectedFiles = ref({});

const handleFileSelect = (event, key) => {
    const file = event.files[0];
    form.settings[key] = file;
    selectedFiles.value[key] = file; // Guardar el objeto del archivo para mostrar su nombre.
};

const clearFileSelection = (key, originalValue) => {
    selectedFiles.value[key] = null;
    form.settings[key] = originalValue; // Revertir al valor original (la URL o null).
}

const submit = () => {
    form.post(route('settings.update'), {
        preserveScroll: true,
        onSuccess: () => {
            // Limpiar el estado temporal de los archivos seleccionados después de un guardado exitoso.
            selectedFiles.value = {};
        },
    });
};

// --- Opciones para los Select fluids ---
const levelOptions = ref([{ label: 'Suscripción', value: 'subscription' }, { label: 'Sucursal', value: 'branch' }, { label: 'Usuario', value: 'user' }]);
const typeOptions = ref([{ label: 'Texto corto (255 caracteres)', value: 'text' }, { label: 'Texto largo (2,000 caracteres)', value: 'long_text' }, { label: 'Número', value: 'number' }, { label: 'Booleano (Sí/No)', value: 'boolean' }, { label: 'Lista', value: 'list' }, { label: 'Selección', value: 'select' }, { label: 'Archivo', value: 'file' }]);
const moduleOptions = ref(['Control financiero', 'Cotizaciones', 'Gastos', 'Historial de ventas', 'Productos', 'Punto de venta', 'Servicios', 'Tienda en linea', 'Impresoras', 'Básculas'].sort((a, b) => a.localeCompare(b)));

// -- Lógica para CREAR y EDITAR (sin cambios) --
const isCreateModalVisible = ref(false);
const createForm = useForm({ name: '', key: '', description: '', module: null, level: 'branch', type: 'text', default_value: '' });
const submitCreateForm = () => { createForm.post(route('settings.store'), { preserveScroll: true, onSuccess: () => { isCreateModalVisible.value = false; createForm.reset(); } }); };
const isEditModalVisible = ref(false);
const editForm = useForm({ id: null, name: '', key: '', description: '', module: null, level: 'branch', type: 'text', default_value: '' });
const isModalVisible = computed({ get: () => isCreateModalVisible.value || isEditModalVisible.value, set: (value) => { isCreateModalVisible.value = value; isEditModalVisible.value = value; } });
const openEditModal = (setting) => {
    Object.assign(editForm, setting);
    if (['list', 'select'].includes(setting.type)) {
        try { editForm.default_value = JSON.parse(setting.default_value) || []; } catch (e) { editForm.default_value = []; }
    }
    isEditModalVisible.value = true;
};
const submitEditForm = () => { editForm.put(route('settings.updateDefinition', editForm.id), { preserveScroll: true, onSuccess: () => isEditModalVisible.value = false }); };
const resetDefaultValue = (formInstance, newType) => { if (['list', 'select'].includes(newType)) { if (!Array.isArray(formInstance.default_value)) formInstance.default_value = []; } else { if (Array.isArray(formInstance.default_value)) formInstance.default_value = ''; } };
watch(() => createForm.type, (newType) => resetDefaultValue(createForm, newType));
watch(() => editForm.type, (newType) => resetDefaultValue(editForm, newType));
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
                    <Button v-if="$page.props.auth.user.id == 1" @click="isCreateModalVisible = true"
                        label="Nueva configuración" icon="pi pi-plus" severity="contrast" class="flex-shrink-0" />
                </header>

                <form @submit.prevent="submit">
                    <Tabs value="0">
                        <TabList>
                            <Tab v-for="(moduleSettings, moduleName, index) in settings" :key="moduleName"
                                :value="index.toString()">
                                {{ moduleName }} ({{ moduleSettings.length }})
                            </Tab>
                        </TabList>
                        <TabPanels>
                            <TabPanel v-for="(moduleSettings, moduleName) in settings" :key="moduleName"
                                :value="Object.keys(settings).indexOf(moduleName).toString()">
                                <div class="p-4 space-y-8">
                                    <div v-for="setting in moduleSettings" :key="setting.id"
                                        class="flex items-start justify-between">
                                        <div class="max-w-lg">
                                            <div class="flex items-center gap-x-2">
                                                <InputLabel :for="setting.key" :value="setting.name"
                                                    class="font-semibold" />
                                                <Badge :value="getLevelLabel(setting.level)"
                                                    :severity="getLevelBadgeSeverity(setting.level)" />
                                                <Button v-if="$page.props.auth.user.id == 1"
                                                    @click="openEditModal(setting)" icon="pi pi-pencil" class="size-6"
                                                    text rounded severity="contrast" />
                                            </div>
                                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{
                                                setting.description
                                                }}</p>
                                        </div>
                                        <div class="flex-shrink-0 ml-4 w-64">
                                            <ToggleSwitch v-if="setting.type === 'boolean'" :inputId="setting.key"
                                                v-model="form.settings[setting.key]" />
                                            <InputText fluid v-if="setting.type === 'text'" :id="setting.key"
                                                v-model="form.settings[setting.key]" class="w-full" />
                                            <Textarea fluid v-if="setting.type === 'long_text'" :id="setting.key"
                                                v-model="form.settings[setting.key]" class="w-full" rows="3" />
                                            <InputNumber fluid v-if="setting.type === 'number'" :inputId="setting.key"
                                                v-model="form.settings[setting.key]" class="w-full" />
                                            <Chips v-if="setting.type === 'list'" :inputId="setting.key"
                                                v-model="form.settings[setting.key]" class="w-full" />
                                            <Select fluid v-if="setting.type === 'select'"
                                                v-model="form.settings[setting.key]" :options="setting.options"
                                                placeholder="Seleccionar" class="w-full" />
                                            <!-- CAMBIO: Lógica mejorada para FileUpload -->
                                            <div v-if="setting.type === 'file'" class="w-full">
                                                <!-- Muestra el archivo ya guardado si no hay uno nuevo seleccionado -->
                                                <a v-if="typeof form.settings[setting.key] === 'string' && form.settings[setting.key] && !selectedFiles[setting.key]"
                                                    :href="form.settings[setting.key]" target="_blank"
                                                    class="text-sm text-sky-600 hover:underline mb-2 block truncate"
                                                    :title="form.settings[setting.key]"> Ver archivo actual</a>

                                                <!-- Muestra el nombre del archivo nuevo que se acaba de seleccionar -->
                                                <div v-if="selectedFiles[setting.key]"
                                                    class="text-sm text-gray-600 dark:text-gray-400 mb-2 flex items-center justify-between bg-gray-100 dark:bg-gray-700 p-2 rounded-md">
                                                    <span class="truncate" :title="selectedFiles[setting.key].name">{{
                                                        selectedFiles[setting.key].name }}</span>
                                                    <Button icon="pi pi-times"
                                                        @click="clearFileSelection(setting.key, setting.value)" text
                                                        rounded class="h-6 w-6 !text-red-500" />
                                                </div>

                                                <FileUpload mode="basic" :name="`settings[${setting.key}]`"
                                                    @select="handleFileSelect($event, setting.key)" :auto="true"
                                                    customUpload
                                                    :chooseLabel="form.settings[setting.key] && typeof form.settings[setting.key] === 'string' ? 'Cambiar archivo' : 'Elegir archivo'"
                                                    class="p-button-sm p-button-outlined" />
                                            </div>
                                            <InputError :message="form.errors[`settings.${setting.key}`]"
                                                class="mt-2" />
                                        </div>
                                    </div>
                                </div>
                            </TabPanel>
                        </TabPanels>
                    </Tabs>
                    <div class="mt-6 flex justify-end p-4 rounded-b-lg sticky bottom-2">
                        <Button type="submit" label="Guardar Cambios" icon="pi pi-check" :loading="form.processing"
                            :disabled="!form.isDirty || form.processing" severity="warning" />
                    </div>
                </form>
            </div>
        </div>

        <Dialog v-model:visible="isModalVisible" modal
            :header="isEditModalVisible ? 'Editar configuración' : 'Crear nueva configuración'"
            :style="{ width: '40rem' }">
            <form @submit.prevent="isEditModalVisible ? submitEditForm() : submitCreateForm()">
                <div class="p-fluid space-y-4">
                    <div class="field">
                        <InputLabel value="Nombre" />
                        <InputText fluid v-model="(isEditModalVisible ? editForm : createForm).name" />
                        <InputError :message="(isEditModalVisible ? editForm : createForm).errors.name" />
                    </div>
                    <div class="field">
                        <InputLabel value="Clave (Key)" />
                        <InputText fluid v-model="(isEditModalVisible ? editForm : createForm).key" />
                        <InputError :message="(isEditModalVisible ? editForm : createForm).errors.key" />
                    </div>
                    <div class="field">
                        <InputLabel value="Descripción" /><Textarea fluid
                            v-model="(isEditModalVisible ? editForm : createForm).description" rows="3" />
                        <InputError :message="(isEditModalVisible ? editForm : createForm).errors.description" />
                    </div>
                    <div class="field">
                        <InputLabel value="Módulo" /><Select fluid
                            v-model="(isEditModalVisible ? editForm : createForm).module" :options="moduleOptions" />
                        <InputError :message="(isEditModalVisible ? editForm : createForm).errors.module" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="field">
                            <InputLabel value="Nivel" /><Select fluid
                                v-model="(isEditModalVisible ? editForm : createForm).level" :options="levelOptions"
                                optionLabel="label" optionValue="value" />
                            <InputError :message="(isEditModalVisible ? editForm : createForm).errors.level" />
                        </div>
                        <div class="field">
                            <InputLabel value="Tipo de Dato" /><Select fluid
                                v-model="(isEditModalVisible ? editForm : createForm).type" :options="typeOptions"
                                optionLabel="label" optionValue="value" />
                            <InputError :message="(isEditModalVisible ? editForm : createForm).errors.type" />
                        </div>
                    </div>
                    <div class="field">
                        <InputLabel
                            :value="['list', 'select'].includes((isEditModalVisible ? editForm : createForm).type) ? 'Opciones (una por una)' : 'Valor por Defecto'" />
                        <Chips v-if="['list', 'select'].includes((isEditModalVisible ? editForm : createForm).type)"
                            v-model="(isEditModalVisible ? editForm : createForm).default_value" />
                        <InputText fluid v-else v-model="(isEditModalVisible ? editForm : createForm).default_value" />
                        <InputError :message="(isEditModalVisible ? editForm : createForm).errors.default_value" />
                    </div>
                </div>
            </form>
            <template #footer>
                <Button label="Cancelar" icon="pi pi-times" @click="isModalVisible = false" text />
                <Button :label="isEditModalVisible ? 'Actualizar' : 'Crear'" icon="pi pi-check"
                    @click="isEditModalVisible ? submitEditForm() : submitCreateForm()"
                    :loading="(isEditModalVisible ? editForm : createForm).processing" />
            </template>
        </Dialog>
    </AppLayout>
</template>