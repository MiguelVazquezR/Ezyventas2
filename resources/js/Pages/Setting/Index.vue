<script setup>
import { ref, watch, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { usePermissions } from '@/Composables/usePermissions';
import { useConfirm } from 'primevue/useconfirm'; // <-- AÑADIDO

const props = defineProps({
    settings: Object,
    availableModules: Array,
});

const { hasPermission } = usePermissions();
const confirm = useConfirm(); // <-- AÑADIDO

// --- Helper para verificar si el usuario puede editar esta configuración ---
const canEditSetting = (setting) => {
    if (setting.level === 'subscription') return hasPermission('settings.generals.update_subscription');
    if (setting.level === 'branch') return hasPermission('settings.generals.update_branch');
    return true; // Las de nivel 'user' siempre están permitidas para el usuario actual
};

// -- Formulario Principal para ACTUALIZAR valores --
const initializeForm = () => {
    const values = {};
    if (props.settings) {
        Object.values(props.settings).flat().forEach(setting => {
            values[setting.key] = setting.value;
        });
    }
    return values;
};
const form = useForm({ settings: initializeForm() });

// Refrescar los datos del formulario cuando las props cambian (tras guardar)
watch(() => props.settings, () => {
    form.defaults({ settings: initializeForm() });
    form.reset();
}, { deep: true });

// --- Gestión de Archivos Temporales ---
const selectedFiles = ref({});

const handleFileSelect = (event, key) => {
    const file = event.files[0];
    form.settings[key] = file;
    selectedFiles.value[key] = file; 
};

const clearFileSelection = (key, originalValue) => {
    selectedFiles.value[key] = null;
    form.settings[key] = originalValue; 
};

const submitForm = () => {
    form.post(route('settings.update'), {
        preserveScroll: true,
        onSuccess: () => {
            selectedFiles.value = {};
        }
    });
};

// --- AÑADIDO: Lógica para Confirmar la Eliminación ---
const confirmDelete = (setting) => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar permanentemente la configuración "${setting.name}"? Esta acción afectará al sistema y no se puede deshacer.`,
        header: 'Eliminar Configuración',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(route('settings.destroy-definition', setting.id), {
                preserveScroll: true,
            });
        }
    });
};

// --- Helpers Visuales para el Nivel ---
const getLevelLabel = (level) => {
    const labels = {
        'subscription': 'Suscripción (Global)',
        'branch': 'Sucursal',
        'user': 'Usuario (Personal)'
    };
    return labels[level] || level;
};

const getLevelSeverity = (level) => {
    const severities = {
        'subscription': 'danger',  // Rojo para niveles globales críticos
        'branch': 'warn',        // Naranja para sucursal
        'user': 'info'           // Azul para usuario
    };
    return severities[level] || 'secondary';
};

// --- Modales para CREAR/EDITAR definiciones (Superadmin) ---
const isModalVisible = ref(false);
const isEditModalVisible = ref(false);

const createForm = useForm({
    name: '', key: '', description: '', module: '', level: 'subscription', type: 'text', default_value: ''
});

const editForm = useForm({
    id: null, name: '', key: '', description: '', module: '', level: 'subscription', type: 'text', default_value: ''
});

const openCreateModal = () => {
    createForm.reset();
    isEditModalVisible.value = false;
    isModalVisible.value = true;
};

const openEditModal = (setting) => {
    editForm.id = setting.id;
    editForm.name = setting.name;
    editForm.key = setting.key;
    editForm.description = setting.description;
    editForm.module = setting.module || ''; // Wait, el modulo lo obtenemos de la iteración, pero lo guardaremos en la BD en un futuro si es necesario.
    editForm.level = setting.level;
    editForm.type = setting.type;
    editForm.default_value = setting.default_value;
    isEditModalVisible.value = true;
    isModalVisible.value = true;
};

const submitCreateForm = () => {
    createForm.post(route('settings.store-definition'), {
        onSuccess: () => isModalVisible.value = false,
    });
};

const submitEditForm = () => {
    editForm.put(route('settings.update-definition', editForm.id), {
        onSuccess: () => isModalVisible.value = false,
    });
};

const typeOptions = [
    { label: 'Texto', value: 'text' },
    { label: 'Número', value: 'number' },
    { label: 'Booleano (Sí/No)', value: 'boolean' },
    { label: 'Lista', value: 'list' },
    { label: 'Archivo / Imagen', value: 'file' },
    { label: 'Selección', value: 'select' },
];

const levelOptions = [
    { label: 'Suscripción (Global)', value: 'subscription' },
    { label: 'Sucursal', value: 'branch' },
    { label: 'Usuario', value: 'user' },
];

// Pestaña activa por defecto
const activeTab = ref('0');
</script>

<template>
    <AppLayout title="Configuraciones">
        <div class="p-4 md:p-6 lg:p-8">
            <header class="mb-6 flex flex-col md:flex-row justify-between md:items-center gap-4">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Configuraciones</h1>
                    <p class="text-gray-500 mt-1 text-sm md:text-base">Administra las preferencias a nivel global, de sucursal o de tu cuenta personal.</p>
                </div>
                
                <!-- Botón solo visible para el superadmin principal (ID 1) con severity="contrast" -->
                <Button v-if="$page.props.auth.user.id == 1" label="Nueva Configuración" icon="pi pi-plus" @click="openCreateModal" severity="contrast" />
            </header>

            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
                
                <!-- Pestañas por Módulo -->
                <Tabs v-model:value="activeTab" scrollable>
                    <TabList>
                        <Tab v-for="(moduleSettings, moduleName, index) in settings" :key="moduleName" :value="String(index)">
                            {{ moduleName }}
                        </Tab>
                    </TabList>
                    
                    <TabPanels>
                        <TabPanel v-for="(moduleSettings, moduleName, index) in settings" :key="moduleName" :value="String(index)">
                            
                            <!-- Grid de Configuraciones del Módulo -->
                            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6 pt-2">
                                
                                <div v-for="setting in moduleSettings" :key="setting.id" 
                                     class="flex flex-col gap-3 p-4 rounded-xl border transition-colors relative"
                                     :class="!canEditSetting(setting) ? 'bg-gray-50/50 border-gray-200 dark:bg-gray-800/50 dark:border-gray-700 opacity-80' : 'bg-white border-gray-300 dark:bg-gray-800 dark:border-gray-600'">
                                    
                                    <!-- Cabecera de la Tarjeta -->
                                    <div class="flex justify-between items-start gap-2">
                                        <div class="flex flex-col flex-1">
                                            <InputLabel :value="setting.name" class="font-bold text-base text-gray-800 dark:text-gray-200" />
                                            <span class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 leading-snug">{{ setting.description }}</span>
                                        </div>
                                        <div class="flex flex-col items-end gap-2 flex-shrink-0">
                                            <!-- Etiqueta del Nivel -->
                                            <Tag :value="getLevelLabel(setting.level)" :severity="getLevelSeverity(setting.level)" class="!text-[10px] uppercase tracking-wider" />
                                            
                                            <!-- Acciones (Developer ID 1) con severity="contrast" -->
                                            <div class="flex items-center gap-1" v-if="$page.props.auth.user.id == 1">
                                                <Button icon="pi pi-pencil" text rounded size="small" severity="contrast" @click="openEditModal(setting)" class="!w-6 !h-6 !p-0" v-tooltip.top="'Editar definición'" />
                                                <Button icon="pi pi-trash" text rounded size="small" severity="contrast" @click="confirmDelete(setting)" class="!w-6 !h-6 !p-0" v-tooltip.top="'Eliminar definición'" />
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Renderizado Dinámico del Input según su Tipo -->
                                    <div class="mt-auto pt-2 border-t border-gray-100 dark:border-gray-700/50">
                                        
                                        <!-- Bloqueo visual secundario -->
                                        <div v-if="!canEditSetting(setting)" class="mb-2 text-[10px] text-red-500 flex items-center gap-1 font-medium">
                                            <i class="pi pi-lock text-[10px]"></i> Sin permisos para editar este nivel
                                        </div>

                                        <InputText v-if="setting.type === 'text'" 
                                            v-model="form.settings[setting.key]" 
                                            :disabled="!canEditSetting(setting)" 
                                            class="w-full" />
                                        
                                        <InputNumber v-else-if="setting.type === 'number'" 
                                            v-model="form.settings[setting.key]" 
                                            :disabled="!canEditSetting(setting)" 
                                            class="w-full" />
                                        
                                        <div v-else-if="setting.type === 'boolean'" class="flex items-center justify-between p-1">
                                            <span class="text-sm font-medium" :class="form.settings[setting.key] ? 'text-green-600' : 'text-gray-500'">
                                                {{ form.settings[setting.key] ? 'Activado' : 'Desactivado' }}
                                            </span>
                                            <InputSwitch v-model="form.settings[setting.key]" :disabled="!canEditSetting(setting)" />
                                        </div>
                                        
                                        <Select v-else-if="setting.type === 'select'" 
                                            v-model="form.settings[setting.key]" 
                                            :options="setting.default_value" 
                                            :disabled="!canEditSetting(setting)" 
                                            class="w-full" />
                                        
                                        <Chips v-else-if="setting.type === 'list'" 
                                            v-model="form.settings[setting.key]" 
                                            :disabled="!canEditSetting(setting)" 
                                            class="w-full" 
                                            separator="," />

                                        <div v-else-if="setting.type === 'file'">
                                            <div v-if="typeof form.settings[setting.key] === 'string' && form.settings[setting.key]" class="flex items-center gap-2 mb-2 bg-gray-100 dark:bg-gray-700 p-2 rounded">
                                                <a :href="form.settings[setting.key]" target="_blank" class="text-blue-600 hover:text-blue-800 text-sm truncate max-w-full font-medium">
                                                    <i class="pi pi-external-link mr-1 text-xs"></i> Ver archivo actual
                                                </a>
                                            </div>
                                            <FileUpload mode="basic" chooseLabel="Seleccionar Archivo" 
                                                :disabled="!canEditSetting(setting)" 
                                                @select="handleFileSelect($event, setting.key)" 
                                                customUpload :auto="false" class="w-full" />
                                            <div v-if="selectedFiles[setting.key]" class="mt-2 flex items-center justify-between bg-blue-50 dark:bg-blue-900/30 p-2 rounded text-sm border border-blue-100 dark:border-blue-800">
                                                <span class="truncate text-blue-800 dark:text-blue-200">{{ selectedFiles[setting.key].name }}</span>
                                                <Button icon="pi pi-times" text rounded severity="danger" class="!w-6 !h-6 !p-0" @click="clearFileSelection(setting.key, setting.value)" />
                                            </div>
                                        </div>

                                    </div>
                                </div>
                            </div>
                            
                            <div v-if="!moduleSettings || moduleSettings.length === 0" class="text-center py-10 text-gray-500">
                                No hay configuraciones en este módulo.
                            </div>
                        </TabPanel>
                    </TabPanels>
                </Tabs>

                <!-- Footer Fijo para Guardar Cambios -->
                <div class="p-4 border-t border-gray-200 dark:border-gray-700 flex justify-end bg-gray-50 dark:bg-gray-800/50 rounded-b-lg">
                    <Button label="Guardar todos los cambios" icon="pi pi-save" @click="submitForm" :loading="form.processing" severity="primary" class="w-full md:w-auto" />
                </div>
            </div>
        </div>

        <!-- Modales de Superadmin (Crear/Editar Definición) -->
        <Dialog v-model:visible="isModalVisible" :header="isEditModalVisible ? 'Editar Configuración (Developer)' : 'Nueva Configuración (Developer)'" modal class="w-full max-w-2xl">
            <form class="space-y-4 pt-2">
                <div class="grid grid-cols-2 gap-4">
                    <div class="field">
                        <InputLabel value="Nombre" />
                        <InputText fluid v-model="(isEditModalVisible ? editForm : createForm).name" />
                        <InputError :message="(isEditModalVisible ? editForm : createForm).errors.name" />
                    </div>
                    <div class="field">
                        <InputLabel value="Clave (Key única)" />
                        <InputText fluid v-model="(isEditModalVisible ? editForm : createForm).key" />
                        <InputError :message="(isEditModalVisible ? editForm : createForm).errors.key" />
                    </div>
                </div>
                <div class="field">
                    <InputLabel value="Descripción" />
                    <Textarea fluid v-model="(isEditModalVisible ? editForm : createForm).description" rows="2" />
                    <InputError :message="(isEditModalVisible ? editForm : createForm).errors.description" />
                </div>
                <div class="grid grid-cols-3 gap-4">
                    <div class="field">
                        <InputLabel value="Módulo (Pestaña)" />
                        <!-- Utilizamos Select pero permitiendo escribir si el componente lo soporta o seleccionando de los disponibles -->
                        <Select fluid v-model="(isEditModalVisible ? editForm : createForm).module" :options="availableModules" editable placeholder="Seleccionar o escribir" />
                        <InputError :message="(isEditModalVisible ? editForm : createForm).errors.module" />
                    </div>
                    <div class="field">
                        <InputLabel value="Nivel de Afectación" />
                        <Select fluid v-model="(isEditModalVisible ? editForm : createForm).level" :options="levelOptions" optionLabel="label" optionValue="value" />
                        <InputError :message="(isEditModalVisible ? editForm : createForm).errors.level" />
                    </div>
                    <div class="field">
                        <InputLabel value="Tipo de Dato en UI" />
                        <Select fluid v-model="(isEditModalVisible ? editForm : createForm).type" :options="typeOptions" optionLabel="label" optionValue="value" />
                        <InputError :message="(isEditModalVisible ? editForm : createForm).errors.type" />
                    </div>
                </div>
                <div class="field">
                    <InputLabel
                        :value="['list', 'select'].includes((isEditModalVisible ? editForm : createForm).type) ? 'Opciones (una por una en JSON o comas)' : 'Valor por Defecto Inicial'" />
                    <Chips v-if="['list', 'select'].includes((isEditModalVisible ? editForm : createForm).type)"
                        v-model="(isEditModalVisible ? editForm : createForm).default_value" />
                    <InputText fluid v-else v-model="(isEditModalVisible ? editForm : createForm).default_value" />
                    <InputError :message="(isEditModalVisible ? editForm : createForm).errors.default_value" />
                </div>
            </form>
            <template #footer>
                <Button label="Cancelar" icon="pi pi-times" @click="isModalVisible = false" text severity="secondary" />
                <Button :label="isEditModalVisible ? 'Actualizar Definición' : 'Crear Definición'" icon="pi pi-check"
                    @click="isEditModalVisible ? submitEditForm() : submitCreateForm()"
                    :loading="(isEditModalVisible ? editForm : createForm).processing" />
            </template>
        </Dialog>
    </AppLayout>
</template>