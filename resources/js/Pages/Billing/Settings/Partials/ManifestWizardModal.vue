<script setup>
import { ref, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';

const props = defineProps({
    fiscalProfile: {
        type: Object,
        required: true,
    },
    canRetryManifestSigning: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['success']);

// ──────────────────────────────────────
// State
// ──────────────────────────────────────
const visible = ref(false);
const step = ref(1);
const textAccepted = ref(false);
const legendError = ref(null);

const legendCerFile = ref(null);
const signCerFile = ref(null);
const signKeyFile = ref(null);

const legendForm = useForm({ cer_file: null });

const manifestForm = useForm({
    cer_file: null,
    key_file: null,
    password: '',
    email: props.fiscalProfile.email || '',
});

// ──────────────────────────────────────
// Decoded manifest text for step 2
// ──────────────────────────────────────
const decodedManifestText = computed(() => {
    if (!props.fiscalProfile.manifest_text_b64) return '';
    try {
        const binary = atob(props.fiscalProfile.manifest_text_b64);
        const bytes = Uint8Array.from(binary, (c) => c.charCodeAt(0));
        return new TextDecoder('utf-8').decode(bytes);
    } catch {
        return '';
    }
});

// ──────────────────────────────────────
// Public API
// ──────────────────────────────────────
function open() {
    legendForm.reset();
    legendForm.clearErrors();
    manifestForm.reset();
    manifestForm.clearErrors();
    manifestForm.email = props.fiscalProfile.email || '';
    textAccepted.value = false;
    legendError.value = null;
    legendCerFile.value = null;
    signCerFile.value = null;
    signKeyFile.value = null;

    if (props.canRetryManifestSigning) {
        step.value = 3;
    } else if (props.fiscalProfile.manifest_text_b64 && props.fiscalProfile.manifest_text_shown_at) {
        step.value = 2;
    } else {
        step.value = 1;
    }

    visible.value = true;
}

defineExpose({ open });

// ──────────────────────────────────────
// Step 1: fetch manifest legend
// ──────────────────────────────────────
function submitFetchLegend() {
    legendForm.cer_file = legendCerFile.value;
    legendForm.post(
        route('billing.fiscal-profiles.manifest.fetch-legend', props.fiscalProfile.id),
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                step.value = 2;
                legendError.value = null;
            },
            onError: (errors) => {
                legendError.value = errors.cer_file || 'Error al obtener el texto del manifiesto.';
            },
        },
    );
}

// ──────────────────────────────────────
// Step 2: accept manifest text
// ──────────────────────────────────────
function acceptText() {
    router.post(
        route('billing.fiscal-profiles.manifest.accept-text', props.fiscalProfile.id),
        { accepted: true },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => { step.value = 3; },
        },
    );
}

// ──────────────────────────────────────
// Step 3: sign with FIEL
// ──────────────────────────────────────
function submitManifest() {
    manifestForm.cer_file = signCerFile.value;
    manifestForm.key_file = signKeyFile.value;
    manifestForm.post(
        route('billing.fiscal-profiles.manifest.sign', props.fiscalProfile.id),
        {
            onSuccess: () => {
                visible.value = false;
                emit('success');
            },
        },
    );
}

// ──────────────────────────────────────
// Tesla UI
// ──────────────────────────────────────
const dialogPt = {
    root: { class: 'dark:!bg-[#232323] !border !border-gray-100 dark:!border-[#3a3a3a] !rounded-3xl !shadow-2xl !overflow-hidden' },
    header: { class: 'dark:!bg-[#232323] !border-b !border-gray-100 dark:!border-[#3a3a3a] !px-6 !py-5' },
    title: { class: '!text-lg !font-medium !text-gray-900 dark:!text-white !tracking-tight !m-0' },
    content: { class: 'dark:!bg-[#232323] !p-6 lg:!p-8' },
    closeButton: { class: '!hover:bg-gray-100 dark:!hover:bg-[#1a1a1a] !transition-colors !rounded-full !w-8 !h-8 !flex !items-center !justify-center' },
    closeButtonIcon: { class: 'dark:!text-gray-400 !text-sm' },
    mask: { class: '!bg-gray-900/60 dark:!bg-black/80' },
};
</script>

<template>
    <Dialog
        v-model:visible="visible"
        :modal="true"
        :draggable="false"
        class="w-full max-w-lg"
        :pt="dialogPt"
    >
        <template #header>
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                    <i class="pi pi-pen-to-square !text-sm text-blue-500"></i>
                </div>
                <div>
                    <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">Firmar Manifiesto SAT</h2>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">Paso {{ step }} de 3</p>
                </div>
            </div>
        </template>

        <!-- ═══ STEP 1 ═══ -->
        <div v-if="step === 1" class="flex flex-col gap-5 pt-2">
            <div class="flex flex-col gap-3 p-4 rounded-2xl bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800">
                <div class="flex items-center gap-2">
                    <i class="pi pi-info-circle !text-sm text-blue-500"></i>
                    <h2 class="text-[13px] text-blue-700 dark:text-blue-300 m-0 leading-relaxed">Carga tu certificado de e.firma (.cer)</h2>
                </div>
                <p class="text-[12px] text-blue-700 dark:text-blue-300 m-0 leading-relaxed">
                    Selecciona el archivo .cer de tu e.firma (FIEL) para consultar el documento exacto que vas a autorizar.</p>
                <p class="text-[12px] font-semibold text-blue-700 dark:text-blue-300 m-0 leading-relaxed">
                    Es totalmente seguro: <span class="font-normal">El archivo .cer es público. Tu llave privada (.key) y contraseña se pedirán únicamente al momento de firmar en el siguiente paso.</span>
                </p>
            </div>
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Certificado FIEL (.cer) *</label>
                <input
                    type="file" accept=".cer"
                    @input="legendCerFile = $event.target.files[0]"
                    class="w-full text-sm text-gray-600 dark:text-gray-400 file:mr-4 file:py-2.5 file:px-5 file:rounded-xl file:border-0 file:text-[10px] file:uppercase file:tracking-widest file:font-bold file:bg-gray-100 dark:file:bg-[#1a1a1a] file:text-gray-700 dark:file:text-gray-300 hover:file:bg-primary-50 dark:hover:file:bg-primary-900/20 file:transition-colors file:cursor-pointer"
                />
                <Message v-if="legendForm.errors.cer_file" severity="error" variant="simple" size="small">{{ legendForm.errors.cer_file }}</Message>
            </div>
        </div>

        <!-- ═══ STEP 2 ═══ -->
        <div v-else-if="step === 2" class="flex flex-col gap-5 pt-2">
            <div class="flex flex-col p-4 rounded-2xl bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800/30">
                <div class="flex items-center gap-2">
                    <i class="pi pi-eye !text-sm text-blue-500"></i>
                    <h2 class="text-[13px] text-blue-700 dark:text-blue-300 m-0 leading-relaxed">Lee el manifiesto del SAT</h2>
                </div>
                <p class="text-[12px] text-blue-700 dark:text-blue-300 m-0 leading-relaxed">
                    Para que podamos generar y enviar tus facturas al SAT, la autoridad exige que autorices a Luna Soft S.A. de C.V. como tu proveedor de facturación.</p>
                <p class="text-[12px] text-blue-700 dark:text-blue-300 m-0 leading-relaxed">
                    Este trámite se realiza una sola vez usando tu e.firma (FIEL). Esto solo nos da permiso para timbrar las facturas que tú generes en la plataforma.
                </p>
            </div>
            <div class="rounded-2xl bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] p-4 max-h-64 overflow-y-auto">
                <p class="text-sm text-gray-700 dark:text-gray-300 m-0 whitespace-pre-wrap leading-relaxed">{{ decodedManifestText }}</p>
            </div>
            <div class="flex items-start gap-3">
                <Checkbox v-model="textAccepted" inputId="acceptManifest" :binary="true" />
                <label for="acceptManifest" class="text-sm text-gray-700 dark:text-gray-300 cursor-pointer select-none">He leído y acepto el manifiesto anterior</label>
            </div>
        </div>

        <!-- ═══ STEP 3 ═══ -->
        <div v-else-if="step === 3" class="flex flex-col gap-5 pt-2">
            <div class="flex flex-col gap-3 p-4 rounded-2xl bg-green-50 dark:bg-green-900/20 border border-green-100 dark:border-green-800">
                <div class="flex items-center gap-2">
                    <i class="pi pi-shield !text-sm text-green-500"></i>
                    <h2 class="text-[13px] text-green-700 dark:text-green-300 m-0 leading-relaxed">Asegúrate de usar tu e.firma (FIEL)</h2>
                </div>
                <p class="text-[12px] text-green-700 dark:text-green-300 m-0 leading-relaxed">
                    Para esta autorización debes subir la FIEL / e.firma. (Los Certificados de Sello Digital - CSD no son válidos para este paso).
                </p>
                <p class="text-[12px] font-semibold text-green-700 dark:text-green-300 m-0 leading-relaxed">
                    100% Seguro: <span class="font-normal">Tu llave privada y contraseña se procesan en memoria en el instante y jamás se almacenan en nuestros servidores.</span>
                </p>
            </div>
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Certificado FIEL (.cer) *</label>
                <input
                    type="file" accept=".cer"
                    @input="signCerFile = $event.target.files[0]"
                    class="w-full text-sm text-gray-600 dark:text-gray-400 file:mr-4 file:py-2.5 file:px-5 file:rounded-xl file:border-0 file:text-[10px] file:uppercase file:tracking-widest file:font-bold file:bg-gray-100 dark:file:bg-[#1a1a1a] file:text-gray-700 dark:file:text-gray-300 hover:file:bg-primary-50 dark:hover:file:bg-primary-900/20 file:transition-colors file:cursor-pointer"
                />
                <Message v-if="manifestForm.errors.cer_file" severity="error" variant="simple" size="small">{{ manifestForm.errors.cer_file }}</Message>
            </div>
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Llave privada FIEL (.key) *</label>
                <input
                    type="file" accept=".key"
                    @input="signKeyFile = $event.target.files[0]"
                    class="w-full text-sm text-gray-600 dark:text-gray-400 file:mr-4 file:py-2.5 file:px-5 file:rounded-xl file:border-0 file:text-[10px] file:uppercase file:tracking-widest file:font-bold file:bg-gray-100 dark:file:bg-[#1a1a1a] file:text-gray-700 dark:file:text-gray-300 hover:file:bg-primary-50 dark:hover:file:bg-primary-900/20 file:transition-colors file:cursor-pointer"
                />
                <Message v-if="manifestForm.errors.key_file" severity="error" variant="simple" size="small">{{ manifestForm.errors.key_file }}</Message>
            </div>
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Contraseña de la FIEL *</label>
                <Password
                    v-model="manifestForm.password"
                    placeholder="Contraseña de tu e.firma"
                    :feedback="false"
                    toggleMask
                    class="w-full"
                    :pt="{ root: { class: '!w-full' }, input: { class: 'w-full min-w-0 !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3' } }"
                />
                <Message v-if="manifestForm.errors.password" severity="error" variant="simple" size="small">{{ manifestForm.errors.password }}</Message>
            </div>
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Correo para recibir copia</label>
                <InputText
                    v-model="manifestForm.email"
                    placeholder="correo@ejemplo.com"
                    class="w-full"
                    :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3' } }"
                />
                <Message v-if="manifestForm.errors.email" severity="error" variant="simple" size="small">{{ manifestForm.errors.email }}</Message>
            </div>
            <div v-if="!manifestForm.errors.cer_file && !manifestForm.errors.key_file && Object.keys(manifestForm.errors).length > 0" class="text-sm text-red-500">
                {{ Object.values(manifestForm.errors).join(' ') }}
            </div>
        </div>

        <template #footer>
            <Button label="Cancelar" severity="secondary" class="!rounded-full" @click="visible = false" />
            <Button v-if="step === 1" label="Obtener texto del manifiesto" icon="pi pi-arrow-right" :loading="legendForm.processing" :disabled="!legendCerFile" class="!rounded-full" @click="submitFetchLegend" />
            <Button v-if="step === 2" label="Continuar" icon="pi pi-arrow-right" :disabled="!textAccepted" class="!rounded-full" @click="acceptText" />
            <Button v-if="step === 3" label="Firmar manifiesto" icon="pi pi-check" :loading="manifestForm.processing" class="!rounded-full" @click="submitManifest" />
        </template>
    </Dialog>
</template>
