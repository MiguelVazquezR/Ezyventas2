<script setup>
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    settingsData: Object, // { definitions_by_module: {}, entities: [] }
    subscriptionId: [Number, String],
});

// --- Selección de entidad ---
const selectedEntity = ref(null);

// Inicializar con la primera entidad cuando los datos lleguen
watch(() => props.settingsData, (data) => {
    if (data?.entities?.length > 0) {
        // Solo inicializar si no hay entidad seleccionada o si la entidad actual ya no existe
        if (!selectedEntity.value || !data.entities.includes(selectedEntity.value)) {
            selectedEntity.value = data.entities[0];
        }
    }
}, { immediate: true });

// --- Pestaña activa ---
const activeModuleTab = ref('0');

// --- Archivos temporales ---
const selectedFiles = ref({});

// --- Formulario ---
const form = useForm({
    entity_type: '',
    entity_id: null,
    settings: {},
});

// Reiniciar formulario cuando cambia la entidad seleccionada
watch(selectedEntity, (entity) => {
    if (!entity) return;

    const values = {};
    const definitions = Object.values(props.settingsData.definitions_by_module).flat();
    definitions.forEach((def) => {
        const raw = entity.values[def.id] ?? def.default_value;
        values[def.key] = raw;
    });

    form.entity_type = entity.type;
    form.entity_id = entity.id;
    form.settings = values;
    form.clearErrors();
    selectedFiles.value = {};
}, { immediate: true });

// --- Helpers ---
const getLevelLabel = (level) => {
    const labels = {
        subscription: 'Suscripción (Global)',
        branch: 'Sucursal',
        user: 'Usuario (Personal)',
    };
    return labels[level] || level;
};

const getLevelSeverity = (level) => {
    const severities = {
        subscription: 'danger',
        branch: 'warn',
        user: 'info',
    };
    return severities[level] || 'secondary';
};

const handleFileSelect = (event, key) => {
    const file = event.files[0];
    form.settings[key] = file;
    selectedFiles.value[key] = file;
};

const clearFileSelection = (key, originalValue) => {
    selectedFiles.value[key] = null;
    // Volver al valor original (string/URL o default)
    const definitions = Object.values(props.settingsData.definitions_by_module).flat();
    const def = definitions.find((d) => d.key === key);
    form.settings[key] = originalValue ?? def?.default_value ?? null;
};

const submitSettings = () => {
    form.post(route('admin.subscriptions.update-settings', props.subscriptionId), {
        preserveScroll: true,
        onSuccess: () => {
            selectedFiles.value = {};
        },
    });
};

// --- Tesla UI PT ---
const selectPt = {
    root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' },
};

const tabsPt = {
    tablist: { class: 'bg-gray-50 dark:bg-[#1a1a1a] border-b border-gray-100 dark:border-[#3a3a3a] px-4' },
    tab: { root: { class: '!text-[11px] !uppercase !tracking-widest !font-bold' } },
    activebar: { class: '!bg-primary-500' },
};

const tagPt = {
    root: { class: '!rounded-full !px-2 !py-0.5 !text-[9px] !uppercase !tracking-widest !font-bold' },
};
</script>

<template>
    <div v-if="settingsData" class="bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] overflow-hidden">
        
        <!-- Header -->
        <div class="p-5 lg:p-6 border-b border-gray-100 dark:border-[#3a3a3a] flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div>
                <h2 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 mb-1 flex items-center gap-2">
                    <i class="pi pi-cog text-gray-400"></i> Configuraciones del sistema
                </h2>
                <p class="text-[10px] text-gray-400 m-0">Selecciona una entidad para ver y editar sus configuraciones</p>
            </div>
            <Button
                label="Guardar cambios"
                icon="pi pi-save"
                severity="primary"
                class="!rounded-xl !text-xs !uppercase !tracking-wider"
                :loading="form.processing"
                @click="submitSettings"
            />
        </div>

        <!-- Selector de entidad -->
        <div class="px-5 lg:px-6 pt-5">
            <div class="flex flex-col gap-1.5 max-w-lg">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Entidad a gestionar</label>
                <Select
                    v-model="selectedEntity"
                    :options="settingsData.entities"
                    optionLabel="name"
                    :pt="selectPt"
                    class="w-full"
                >
                    <template #value="slotProps">
                        <div v-if="slotProps.value" class="flex items-center gap-2">
                            <i :class="slotProps.value.type === 'subscription' ? 'pi pi-globe' : slotProps.value.type === 'branch' ? 'pi pi-building' : 'pi pi-user'" class="text-gray-400 !text-xs"></i>
                            <span class="text-sm">{{ slotProps.value.name }}</span>
                        </div>
                    </template>
                    <template #option="slotProps">
                        <div class="flex items-center gap-2">
                            <i :class="slotProps.option.type === 'subscription' ? 'pi pi-globe' : slotProps.option.type === 'branch' ? 'pi pi-building' : 'pi pi-user'" class="text-gray-400 !text-xs"></i>
                            <span>{{ slotProps.option.name }}</span>
                        </div>
                    </template>
                </Select>
            </div>
        </div>

        <!-- Tabs por módulo -->
        <div v-if="selectedEntity" class="mt-4">
            <Tabs v-model:value="activeModuleTab" scrollable :pt="tabsPt">
                <TabList>
                    <Tab v-for="(moduleDefs, moduleName, idx) in settingsData.definitions_by_module" :key="moduleName" :value="String(idx)">
                        {{ moduleName }}
                    </Tab>
                </TabList>

                <TabPanels>
                    <TabPanel v-for="(moduleDefs, moduleName, idx) in settingsData.definitions_by_module" :key="moduleName" :value="String(idx)">
                        <div class="p-5 lg:p-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                                
                                <div
                                    v-for="setting in moduleDefs"
                                    :key="setting.id"
                                    class="flex flex-col gap-3 p-4 rounded-2xl border transition-colors bg-gray-50 dark:bg-[#1a1a1a] border-gray-100 dark:border-[#3a3a3a]"
                                >
                                    <!-- Cabecera -->
                                    <div class="flex justify-between items-start gap-2">
                                        <div class="flex flex-col flex-1 min-w-0">
                                            <label class="text-sm font-medium text-gray-800 dark:text-gray-200 m-0 leading-snug">{{ setting.name }}</label>
                                            <span class="text-[10px] text-gray-500 dark:text-gray-400 mt-0.5 leading-snug line-clamp-2">{{ setting.description }}</span>
                                        </div>
                                        <Tag :value="getLevelLabel(setting.level)" :severity="getLevelSeverity(setting.level)" :pt="tagPt" />
                                    </div>

                                    <!-- Input según tipo -->
                                    <div class="mt-auto pt-3 border-t border-gray-200 dark:border-[#3a3a3a]/50">

                                        <!-- Texto -->
                                        <InputText
                                            v-if="setting.type === 'text'"
                                            v-model="form.settings[setting.key]"
                                            class="w-full"
                                            :pt="{ root: { class: '!rounded-xl !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !text-sm' } }"
                                        />

                                        <!-- Número -->
                                        <InputNumber
                                            v-else-if="setting.type === 'number'"
                                            v-model="form.settings[setting.key]"
                                            class="w-full"
                                            :pt="{ input: { root: { class: 'w-full !rounded-xl !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !text-sm' } } }"
                                        />

                                        <!-- Booleano -->
                                        <div v-else-if="setting.type === 'boolean'" class="flex items-center justify-between py-1 px-1">
                                            <span class="text-xs font-medium" :class="form.settings[setting.key] ? 'text-green-600' : 'text-gray-400'">
                                                {{ form.settings[setting.key] ? 'Activado' : 'Desactivado' }}
                                            </span>
                                            <InputSwitch v-model="form.settings[setting.key]" />
                                        </div>

                                        <!-- Select -->
                                        <Select
                                            v-else-if="setting.type === 'select'"
                                            v-model="form.settings[setting.key]"
                                            :options="setting.default_value"
                                            class="w-full"
                                            :pt="{ root: { class: '!rounded-xl !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !text-sm' } }"
                                        />

                                        <!-- Lista (Chips) -->
                                        <Chips
                                            v-else-if="setting.type === 'list'"
                                            v-model="form.settings[setting.key]"
                                            class="w-full"
                                            separator=","
                                            :pt="{ root: { class: '!rounded-xl !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a]' } }"
                                        />

                                        <!-- Archivo -->
                                        <div v-else-if="setting.type === 'file'">
                                            <div
                                                v-if="typeof form.settings[setting.key] === 'string' && form.settings[setting.key]"
                                                class="flex items-center gap-2 mb-2 bg-gray-200 dark:bg-[#2a2a2a] p-2 rounded-xl"
                                            >
                                                <a :href="form.settings[setting.key]" target="_blank" class="text-blue-500 hover:text-blue-400 text-xs truncate max-w-full font-medium">
                                                    <i class="pi pi-external-link mr-1 !text-[10px]"></i> Ver archivo actual
                                                </a>
                                            </div>
                                            <FileUpload
                                                mode="basic"
                                                chooseLabel="Seleccionar archivo"
                                                @select="handleFileSelect($event, setting.key)"
                                                customUpload
                                                :auto="false"
                                                class="w-full"
                                                :pt="{ chooseButton: { class: '!rounded-xl !text-xs !bg-gray-200 dark:!bg-[#2a2a2a] !border-gray-200 dark:!border-[#3a3a3a] !text-gray-700 dark:!text-gray-300' } }"
                                            />
                                            <div
                                                v-if="selectedFiles[setting.key]"
                                                class="mt-2 flex items-center justify-between bg-blue-50 dark:bg-blue-900/20 p-2 rounded-xl text-xs border border-blue-100 dark:border-blue-800"
                                            >
                                                <span class="truncate text-blue-800 dark:text-blue-200">{{ selectedFiles[setting.key].name }}</span>
                                                <Button icon="pi pi-times" text rounded size="small" severity="danger" class="!w-5 !h-5 !p-0" @click="clearFileSelection(setting.key, setting.default_value)" />
                                            </div>
                                        </div>

                                    </div>
                                </div>

                                <div v-if="moduleDefs.length === 0" class="col-span-full text-center py-8 text-gray-400 text-sm">
                                    No hay configuraciones en este módulo.
                                </div>
                            </div>
                        </div>
                    </TabPanel>
                </TabPanels>
            </Tabs>
        </div>

        <!-- Estado vacío si no hay entidad seleccionada -->
        <div v-else class="p-10 text-center text-gray-400 text-sm">
            <i class="pi pi-inbox !text-3xl mb-3 block"></i>
            Selecciona una entidad para gestionar sus configuraciones.
        </div>

    </div>
</template>
