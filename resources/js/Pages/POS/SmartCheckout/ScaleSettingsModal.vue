<script setup>
import { computed, reactive, ref, watch } from 'vue';
import axios from 'axios';
import { useToast } from 'primevue/usetoast';
import { useScaleSession } from '@/Composables/smartCheckout/useScaleSession';

const props = defineProps({
    visible: { type: Boolean, default: false },
    canConfigure: { type: Boolean, default: false },
});

const emit = defineEmits(['update:visible', 'saved']);

const toast = useToast();

const loading = ref(false);
const saving = ref(false);
const loadError = ref(null);
const errors = ref({});
const showDiagnostics = ref(false);

const form = reactive({
    driver: 'simulated',
    active_profile_id: null,
    profiles: [],
    stability: { readings: 5, tolerance_g: 3 },
});

const isOpen = computed({
    get: () => props.visible,
    set: (value) => emit('update:visible', value),
});

const activeProfile = computed(() => form.profiles.find((profile) => profile.id === form.active_profile_id) || null);

const driverOptions = [
    { label: 'Báscula simulada (desarrollo)', value: 'simulated' },
    { label: 'Captura manual del peso', value: 'manual' },
    { label: 'Báscula por cable USB (Web Serial)', value: 'webserial' },
];

const parityOptions = ['none', 'even', 'odd'].map((value) => ({ label: value, value }));
const terminatorOptions = [
    { label: 'LF (\\n)', value: 'lf' },
    { label: 'CR (\\r)', value: 'cr' },
    { label: 'CRLF (\\r\\n)', value: 'crlf' },
];

const webserialSupported = computed(() => typeof navigator !== 'undefined' && 'serial' in navigator);

const diagnostics = useScaleSession({ kind: 'webserial', settings: null });

function profileTemplate(index) {
    return {
        id: `perfil-${Date.now()}-${index}`,
        name: `Báscula ${index + 1}`,
        baudRate: 9600,
        dataBits: 8,
        stopBits: 1,
        parity: 'none',
        terminator: 'lf',
        pattern: '^ST,GS,\\s*([+-]?\\d+(?:[.,]\\d+)?)\\s*(kg|g)?\\s*$',
        weightGroup: 1,
        unitGroup: 2,
        stableGroup: null,
        unit: 'kg',
        notes: '',
    };
}

function addProfile() {
    if (form.profiles.length >= 10) {
        toast.add({ severity: 'warn', summary: 'Límite alcanzado', detail: 'Puedes guardar hasta 10 perfiles de báscula.', life: 3000 });
        return;
    }
    const profile = profileTemplate(form.profiles.length);
    form.profiles.push(profile);
    form.active_profile_id = profile.id;
}

function removeProfile(profileId) {
    form.profiles = form.profiles.filter((profile) => profile.id !== profileId);
    if (form.active_profile_id === profileId) {
        form.active_profile_id = form.profiles[0]?.id || null;
    }
}

function duplicateProfile(profile) {
    if (form.profiles.length >= 10) return;
    const copy = { ...profile, id: `${profile.id}-copia-${Date.now()}`, name: `${profile.name} (copia)` };
    form.profiles.push(copy);
    form.active_profile_id = copy.id;
}

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await axios.get(route('pos.smart-checkout.scale.settings'));
        const settings = data.settings || {};
        form.driver = settings.driver || 'simulated';
        form.active_profile_id = settings.active_profile_id ?? null;
        form.profiles = Array.isArray(settings.profiles) ? settings.profiles.map((profile) => ({ ...profile })) : [];
        form.stability = {
            readings: settings.stability?.readings ?? 5,
            tolerance_g: settings.stability?.tolerance_g ?? 3,
        };
        diagnostics.settings.value = { ...settings, driver: 'webserial' };
    } catch (error) {
        loadError.value = error?.response?.data?.message || 'No se pudo cargar la configuración de la báscula.';
    } finally {
        loading.value = false;
    }
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        await axios.put(route('pos.smart-checkout.scale.settings.update'), {
            driver: form.driver,
            active_profile_id: form.active_profile_id,
            profiles: form.profiles,
            stability: {
                readings: Number(form.stability.readings) || 5,
                tolerance_g: Number(form.stability.tolerance_g) || 3,
            },
        });
        toast.add({ severity: 'success', summary: 'Báscula actualizada', detail: 'La configuración se guardó para este negocio.', life: 3000 });
        emit('saved', form.driver);
        isOpen.value = false;
    } catch (error) {
        if (error?.response?.status === 422) {
            errors.value = error.response.data.errors || {};
            toast.add({ severity: 'error', summary: 'Revisa los datos', detail: 'Hay campos con errores en la configuración.', life: 4000 });
        } else if (error?.response?.status === 403) {
            toast.add({ severity: 'error', summary: 'Sin permiso', detail: 'No tienes permiso para configurar la báscula.', life: 4000 });
        } else {
            toast.add({ severity: 'error', summary: 'No se pudo guardar', detail: error?.response?.data?.message || 'Intenta de nuevo.', life: 4000 });
        }
    } finally {
        saving.value = false;
    }
}

function errorFor(field) {
    const value = errors.value[field];
    return Array.isArray(value) ? value[0] : value;
}

watch(
    () => props.visible,
    async (open) => {
        if (open) {
            await load();
        } else {
            await diagnostics.disconnect();
        }
    },
);

watch(
    () => showDiagnostics.value,
    async (open) => {
        if (!open) {
            await diagnostics.disconnect();
            return;
        }
        if (!webserialSupported.value) {
            showDiagnostics.value = false;
            toast.add({
                severity: 'warn',
                summary: 'Sin Web Serial',
                detail: 'Este navegador no admite Web Serial. Usa Chrome o Edge actualizado sobre HTTPS.',
                life: 5000,
            });
            return;
        }
        diagnostics.settings.value = {
            ...diagnostics.settings.value,
            active_profile_id: activeProfile.value?.id ?? null,
            profiles: form.profiles,
            stability: form.stability,
            driver: 'webserial',
        };
        await diagnostics.connect();
    },
);
</script>

<template>
    <Dialog
        v-model:visible="isOpen"
        modal
        :style="{ width: '760px', maxWidth: '96vw' }"
        :pt="{
            root: { class: '!rounded-3xl !border !border-gray-100 dark:!border-[#3a3a3a] !bg-white dark:!bg-[#232323] !shadow-2xl' },
            header: { class: '!px-6 !pt-6 !pb-2 !border-none' },
            content: { class: '!px-6 !pt-2 !pb-6' },
            closeButton: { class: '!text-gray-400 hover:!text-gray-600 dark:hover:!text-gray-200' },
        }"
        :draggable="false"
    >
        <template #header>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-2xl flex items-center justify-center bg-primary-500/10 text-primary-500">
                    <i class="pi pi-cog text-lg" aria-hidden="true"></i>
                </div>
                <div>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Caja inteligente</p>
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white m-0">Configuración de la báscula</h3>
                </div>
            </div>
        </template>

        <div v-if="loading" class="flex items-center justify-center py-16 text-gray-400">
            <i class="pi pi-spin pi-spinner mr-2"></i> Cargando configuración...
        </div>

        <Message v-else-if="loadError" severity="error" variant="simple" size="small">{{ loadError }}</Message>

        <div v-else class="space-y-5 max-h-[70vh] overflow-y-auto pr-1">
            <!-- Tipo de báscula -->
            <section>
                <h4 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 mb-2">Tipo de conexión</h4>
                <Select
                    v-model="form.driver"
                    :options="driverOptions"
                    optionLabel="label"
                    optionValue="value"
                    class="w-full"
                    :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a]' } }"
                />
                <p class="text-[11px] text-gray-400 mt-1 m-0">
                    La captura manual siempre está disponible, aunque no haya báscula conectada.
                </p>
            </section>

            <!-- Estabilidad -->
            <section>
                <h4 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 mb-2">Lectura estable</h4>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="scale-readings" class="block text-[11px] font-bold text-gray-500 mb-1">Lecturas consecutivas</label>
                        <InputNumber
                            id="scale-readings"
                            v-model="form.stability.readings"
                            :min="2"
                            :max="20"
                            :step="1"
                            showButtons
                            class="w-full"
                            :inputClass="'!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a]'"
                        />
                        <p v-if="errorFor('stability.readings')" class="text-[11px] text-red-500 mt-1 m-0">{{ errorFor('stability.readings') }}</p>
                    </div>
                    <div>
                        <label for="scale-tolerance" class="block text-[11px] font-bold text-gray-500 mb-1">Tolerancia (gramos)</label>
                        <InputNumber
                            id="scale-tolerance"
                            v-model="form.stability.tolerance_g"
                            :min="1"
                            :max="100"
                            :step="1"
                            showButtons
                            class="w-full"
                            :inputClass="'!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a]'"
                        />
                        <p v-if="errorFor('stability.tolerance_g')" class="text-[11px] text-red-500 mt-1 m-0">{{ errorFor('stability.tolerance_g') }}</p>
                    </div>
                </div>
                <p class="text-[11px] text-gray-400 mt-2 m-0">
                    Un peso se considera estable cuando varía menos de esa tolerancia durante esas lecturas seguidas.
                </p>
            </section>

            <!-- Perfiles seriales -->
            <section>
                <div class="flex items-center justify-between mb-2">
                    <h4 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Perfiles de báscula</h4>
                    <Button label="Agregar perfil" icon="pi pi-plus" size="small" text class="!text-[11px] !font-bold" @click="addProfile" />
                </div>

                <p v-if="!form.profiles.length" class="text-[11px] text-gray-400 m-0">
                    Sin perfiles. Agrega uno para configurar baudios, terminador y el patrón que extrae peso, unidad y estabilidad
                    de cada línea de la báscula.
                </p>

                <div v-else class="space-y-3">
                    <div
                        v-for="profile in form.profiles"
                        :key="profile.id"
                        class="rounded-2xl border p-3 transition-colors"
                        :class="profile.id === form.active_profile_id ? 'border-primary-500 bg-primary-500/5' : 'border-gray-100 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#1a1a1a]'"
                    >
                        <div class="flex items-center gap-2 mb-3">
                            <InputText
                                v-model="profile.name"
                                class="flex-1 !text-sm !rounded-xl !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a]"
                                placeholder="Nombre del perfil"
                            />
                            <Button
                                icon="pi pi-check"
                                rounded
                                size="small"
                                :severity="profile.id === form.active_profile_id ? 'success' : 'secondary'"
                                :outlined="profile.id !== form.active_profile_id"
                                :aria-label="`Usar el perfil ${profile.name}`"
                                @click="form.active_profile_id = profile.id"
                            />
                            <Button icon="pi pi-copy" rounded text size="small" aria-label="Duplicar perfil" @click="duplicateProfile(profile)" />
                            <Button icon="pi pi-trash" rounded text size="small" severity="danger" aria-label="Eliminar perfil" @click="removeProfile(profile.id)" />
                        </div>

                        <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-500 mb-1">Baudios</label>
                                <InputNumber v-model="profile.baudRate" :min="1200" :max="115200" :step="1200" class="w-full" :inputClass="'!rounded-xl !text-xs !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a]'" />
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-500 mb-1">Bits de datos</label>
                                <InputNumber v-model="profile.dataBits" :min="7" :max="8" showButtons class="w-full" :inputClass="'!rounded-xl !text-xs !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a]'" />
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-500 mb-1">Paridad</label>
                                <Select v-model="profile.parity" :options="parityOptions" optionLabel="label" optionValue="value" class="w-full" :pt="{ root: { class: '!rounded-xl !text-xs !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a]' } }" />
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-500 mb-1">Bits de parada</label>
                                <InputNumber v-model="profile.stopBits" :min="1" :max="2" showButtons class="w-full" :inputClass="'!rounded-xl !text-xs !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a]'" />
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2 mt-2">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-500 mb-1">Terminador de línea</label>
                                <Select v-model="profile.terminator" :options="terminatorOptions" optionLabel="label" optionValue="value" class="w-full" :pt="{ root: { class: '!rounded-xl !text-xs !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a]' } }" />
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-500 mb-1">Unidad por defecto</label>
                                <Select v-model="profile.unit" :options="['kg', 'g']" class="w-full" :pt="{ root: { class: '!rounded-xl !text-xs !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a]' } }" />
                            </div>
                        </div>

                        <div class="mt-2">
                            <label class="block text-[10px] font-bold text-gray-500 mb-1">Expresión regular de la trama</label>
                            <InputText v-model="profile.pattern" class="w-full !text-xs !font-mono !rounded-xl !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a]" />
                            <p v-if="errorFor(`profiles`) || errorFor(`profiles.${form.profiles.indexOf(profile)}.pattern`)" class="text-[11px] text-red-500 mt-1 m-0">
                                {{ errorFor(`profiles.${form.profiles.indexOf(profile)}.pattern`) || errorFor('profiles') }}
                            </p>
                        </div>

                        <div class="grid grid-cols-3 gap-2 mt-2">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-500 mb-1">Grupo peso</label>
                                <InputNumber v-model="profile.weightGroup" :min="1" :max="9" showButtons class="w-full" :inputClass="'!rounded-xl !text-xs !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a]'" />
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-500 mb-1">Grupo unidad</label>
                                <InputNumber v-model="profile.unitGroup" :min="1" :max="9" showButtons class="w-full" :inputClass="'!rounded-xl !text-xs !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a]'" />
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-gray-500 mb-1">Grupo estable</label>
                                <InputNumber v-model="profile.stableGroup" :min="1" :max="9" showButtons class="w-full" :inputClass="'!rounded-xl !text-xs !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a]'" />
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Diagnóstico -->
            <section>
                <button
                    type="button"
                    class="flex w-full items-center justify-between rounded-2xl border border-dashed border-gray-200 dark:border-[#3a3a3a] px-3 py-2"
                    @click="showDiagnostics = !showDiagnostics"
                >
                    <span class="inline-flex items-center gap-2 text-[11px] font-bold text-gray-500 dark:text-gray-400">
                        <i class="pi pi-desktop" aria-hidden="true"></i> Modo diagnóstico (líneas crudas del puerto)
                    </span>
                    <i :class="showDiagnostics ? 'pi pi-chevron-up' : 'pi pi-chevron-down'" aria-hidden="true"></i>
                </button>

                <div v-if="showDiagnostics" class="mt-2">
                    <div class="flex items-center gap-2 mb-2">
                        <Button label="Conectar puerto" icon="pi pi-link" size="small" :loading="diagnostics.connecting.value" class="!rounded-xl !text-[11px] !font-bold" @click="diagnostics.connect()" />
                        <Button label="Limpiar" icon="pi pi-trash" size="small" text severity="secondary" class="!rounded-xl !text-[11px]" @click="diagnostics.clearRawLines()" />
                        <span class="text-[11px] text-gray-400 ml-auto">{{ diagnostics.connected.value ? 'Puerto abierto' : 'Puerto cerrado' }}</span>
                    </div>
                    <Message v-if="diagnostics.error.value" severity="error" variant="simple" size="small" class="mb-2">{{ diagnostics.error.value }}</Message>
                    <pre class="text-[11px] font-mono bg-gray-900 text-gray-100 rounded-xl p-3 h-32 overflow-auto m-0">{{ diagnostics.rawLines.value.length ? diagnostics.rawLines.value.join('\n') : 'Sin datos del puerto todavía.' }}</pre>
                </div>
            </section>

            <p class="text-[11px] text-gray-400 m-0">
                Los ajustes se guardan por negocio. Cada cajero conserva su tipo de conexión en esta terminal.
            </p>
        </div>

        <template #footer>
            <div class="flex items-center justify-end gap-2">
                <Button label="Cancelar" severity="secondary" outlined class="!rounded-xl !font-bold" @click="isOpen = false" />
                <Button label="Guardar configuración" icon="pi pi-check" class="!rounded-xl !font-bold" :loading="saving" @click="save" />
            </div>
        </template>
    </Dialog>
</template>
