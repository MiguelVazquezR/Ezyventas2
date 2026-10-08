<script setup>
import { ref, computed } from 'vue';
import axios from 'axios';

const props = defineProps({
    fiscalProfiles: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['success']);

// ──────────────────────────────────────
// State
// ──────────────────────────────────────
const visible = ref(false);
const activeTab = ref('responder'); // 'responder' | 'historial'

// Responder form
const rfcId = ref(null);
const uuid = ref('');
const decision = ref(null); // 'Aceptacion' | 'Rechazo'
const submitting = ref(false);
const errorMessage = ref('');
const result = ref(null);

// Historial
const history = ref([]);
const historyLoading = ref(false);
const historyError = ref('');

// ──────────────────────────────────────
// Helpers
// ──────────────────────────────────────
const rfcOptions = computed(() =>
    (props.fiscalProfiles || []).map((p) => ({
        label: `${p.razon_social} (${p.rfc})`,
        value: p.id,
    })),
);

const submitLabel = computed(() =>
    decision.value === 'Rechazo' ? 'Enviar respuesta (rechazar)' : 'Enviar respuesta (aceptar)',
);

const submitIcon = computed(() =>
    decision.value === 'Rechazo' ? 'pi pi-times-circle' : 'pi pi-check-circle',
);

// Once a response was sent the form becomes read-only (informational only).
const locked = computed(() => !!result.value);

const resultLabel = (action) => (action === 'Rechazo' ? 'Se rechazó la cancelación' : 'Se aceptó la cancelación');

const resultSeverity = (action) => (action === 'Rechazo' ? 'danger' : 'success');

const truncateUuid = (value) => {
    if (!value) return '—';
    return value.length > 16 ? `${value.slice(0, 8)}...${value.slice(-8)}` : value;
};

const formatDate = (dateString) => {
    if (!dateString) return '—';
    return new Date(dateString).toLocaleString('es-MX', {
        year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
    });
};

// ──────────────────────────────────────
// Actions
// ──────────────────────────────────────
function open() {
    rfcId.value = props.fiscalProfiles?.[0]?.id ?? null;
    uuid.value = '';
    decision.value = null;
    errorMessage.value = '';
    result.value = null;
    activeTab.value = 'responder';
    visible.value = true;
}

function close() {
    visible.value = false;
}

// Auto-format the UUID while typing: only hex chars (0-9, A-F), uppercased,
// hyphen groups 8-4-4-4-12, capped at 36 characters.
function formatUuidInput() {
    const clean = uuid.value.replace(/[^0-9a-fA-F]/g, '').toUpperCase().slice(0, 32);
    const groups = [8, 4, 4, 4, 12];
    let formatted = '';
    let idx = 0;
    for (let i = 0; i < groups.length; i++) {
        if (idx >= clean.length) break;
        formatted += clean.slice(idx, idx + groups[i]);
        idx += groups[i];
        if (idx < clean.length) formatted += '-';
    }
    uuid.value = formatted;
}

async function submit() {
    errorMessage.value = '';
    if (!rfcId.value) {
        errorMessage.value = 'Selecciona el RFC receptor.';
        return;
    }
    if (!uuid.value) {
        errorMessage.value = 'Ingresa el UUID de la factura recibida.';
        return;
    }
    if (uuid.value.replace(/-/g, '').length !== 32) {
        errorMessage.value = 'El UUID debe completar el formato de 36 caracteres (8-4-4-4-12).';
        return;
    }
    if (!decision.value) {
        errorMessage.value = 'Selecciona si aceptas o rechazas la cancelación.';
        return;
    }

    submitting.value = true;
    try {
        const { data } = await axios.post(route('billing.invoices.acceptReject'), {
            fiscal_profile_id: rfcId.value,
            uuid: uuid.value.trim(),
            action: decision.value,
        });

        result.value = {
            message: data.message,
            acuse: data.data?.acuse || null,
            folios: data.data?.folios || [],
        };
        emit('success');
        await loadHistory();
    } catch (error) {
        errorMessage.value = error?.response?.data?.message
            || 'No se pudo enviar la respuesta. Intenta de nuevo.';
    } finally {
        submitting.value = false;
    }
}

async function loadHistory() {
    historyLoading.value = true;
    historyError.value = '';
    try {
        const { data } = await axios.get(route('billing.invoices.acceptRejectHistory'));
        history.value = data.data || [];
    } catch {
        historyError.value = 'No se pudo cargar el historial.';
    } finally {
        historyLoading.value = false;
    }
}

function onTabChange(tab) {
    activeTab.value = tab;
    if (tab === 'historial' && history.value.length === 0) {
        loadHistory();
    }
}

// ──────────────────────────────────────
// Style helpers (Tesla UI)
// ──────────────────────────────────────
const tabClass = (tab) =>
    tab === activeTab.value
        ? 'flex-1 rounded-2xl border border-primary-500 bg-primary-500/10 px-4 py-3 text-sm font-semibold text-gray-900 dark:text-white transition-colors'
        : 'flex-1 rounded-2xl border border-gray-200 dark:border-gray-700 px-4 py-3 text-sm font-semibold text-gray-500 dark:text-gray-400 transition-colors hover:text-gray-900 dark:hover:text-white hover:border-gray-400 dark:hover:border-gray-500';

const decisionCardClass = (value) => {
    // After responding, keep the chosen card highlighted and disable the other.
    if (locked.value) {
        if (decision.value === value) {
            return value === 'Rechazo'
                ? 'rounded-2xl border border-red-500/70 bg-red-500/10 p-4 text-left cursor-default'
                : 'rounded-2xl border border-emerald-500/70 bg-emerald-500/10 p-4 text-left cursor-default';
        }

        return 'rounded-2xl border border-gray-200 dark:border-gray-700 p-4 text-left opacity-40 cursor-not-allowed';
    }

    return decision.value === value
        ? (value === 'Rechazo'
            ? 'rounded-2xl border border-red-500/70 bg-red-500/10 p-4 text-left transition-colors'
            : 'rounded-2xl border border-emerald-500/70 bg-emerald-500/10 p-4 text-left transition-colors')
        : 'rounded-2xl border border-gray-200 dark:border-gray-700 p-4 text-left transition-colors hover:border-emerald-500/60 dark:hover:border-emerald-500/60';
};

const dialogPt = {
    root: { class: 'dark:!bg-[#232323] !border !border-gray-100 dark:!border-[#3a3a3a] !rounded-3xl !shadow-2xl !overflow-hidden' },
    header: { class: 'dark:!bg-[#232323] !border-b !border-gray-100 dark:!border-[#3a3a3a] !px-6 !py-5' },
    title: { class: '!text-lg !font-medium !text-gray-900 dark:!text-white !tracking-tight !m-0' },
    content: { class: 'dark:!bg-[#232323] !p-6 lg:!p-8' },
    closeButton: { class: '!hover:bg-gray-100 dark:!hover:bg-[#1a1a1a] !transition-colors !rounded-full !w-8 !h-8 !flex !items-center !justify-center' },
    closeButtonIcon: { class: 'dark:!text-gray-400 !text-sm' },
    mask: { class: '!bg-gray-900/60 dark:!bg-black/80' },
};

const selectPt = {
    root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' },
};

const inputPt = {
    root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 !py-3' },
};

const dataTablePt = {
    root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
    headerRow: { class: 'bg-gray-50 dark:bg-[#1a1a1a]' },
    headerCell: { class: 'bg-transparent !text-[10px] !uppercase !tracking-widest !font-bold !text-gray-400 py-3 px-4 border-b border-gray-100 dark:border-[#3a3a3a]' },
    bodyRow: { class: 'dark:bg-[#232323] hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors text-sm text-gray-700 dark:text-gray-300' },
    bodyCell: { class: 'py-3 px-4 border-b border-gray-50 dark:border-[#2a2a2a]' },
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' },
};

defineExpose({ open });
</script>

<template>
    <Dialog
        v-model:visible="visible"
        modal
        class="w-full max-w-2xl mx-4"
        :pt="dialogPt"
    >
        <template #header>
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-full bg-amber-50 dark:bg-amber-900/20 text-amber-500 flex items-center justify-center flex-shrink-0 border border-amber-100 dark:border-amber-900/30">
                    <i class="pi pi-exclamation-triangle !text-sm"></i>
                </div>
                <div>
                    <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">Responder solicitud de cancelación</h2>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">
                        Acepta o rechaza ante el SAT
                    </p>
                </div>
            </div>
        </template>

        <div class="space-y-5 pt-2">
            <!-- Tabs -->
            <div class="flex items-center gap-3">
                <button type="button" @click="onTabChange('responder')" :class="tabClass('responder')">
                    <i class="pi pi-pencil !text-xs mr-2"></i> Responder
                </button>
                <button type="button" @click="onTabChange('historial')" :class="tabClass('historial')">
                    <i class="pi pi-history !text-xs mr-2"></i> Historial
                </button>
            </div>

            <!-- ═══════════ RESPONDER ═══════════ -->
            <div v-if="activeTab === 'responder'" class="space-y-5">
                <!-- Result -->
                <Message
                    v-if="result"
                    severity="success"
                    variant="simple"
                    size="small"
                >
                    {{ result.message }}
                </Message>

                <div
                    v-if="result"
                    class="flex items-start gap-2.5 px-4 py-3 rounded-2xl bg-emerald-50/60 dark:bg-emerald-900/10 border border-emerald-100 dark:border-emerald-900/20"
                >
                    <i class="pi pi-check-circle !text-xs text-emerald-500 mt-0.5"></i>
                    <div class="flex flex-col gap-1.5 min-w-0 flex-1">
                        <p class="text-xs text-emerald-700 dark:text-emerald-300 m-0 font-medium">
                            UUID: <span class="font-mono">{{ uuid }}</span> · Resultado: {{ resultLabel(decision) }}
                        </p>
                        <div
                            v-if="result.acuse"
                            class="max-h-40 overflow-auto rounded-xl bg-white dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] p-3"
                        >
                            <pre class="font-mono text-[10px] text-gray-500 dark:text-gray-400 whitespace-pre-wrap break-all m-0">{{ result.acuse }}</pre>
                        </div>
                    </div>
                </div>

                <!-- RFC -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                        RFC receptor (tu RFC) *
                    </label>
                    <Select
                        v-model="rfcId"
                        :options="rfcOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Selecciona tu perfil fiscal"
                        class="w-full"
                        :pt="selectPt"
                        :disabled="locked"
                    />
                    <p class="text-xs text-gray-400 dark:text-gray-500 m-0">
                        Debe ser el RFC con el que apareces como receptor en la factura del proveedor.
                    </p>
                </div>

                <!-- UUID -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                        UUID de la factura recibida *
                    </label>
                    <InputText
                        v-model="uuid"
                        placeholder="00000000-0000-0000-0000-000000000000"
                        class="w-full font-mono text-sm"
                        :pt="inputPt"
                        @input="formatUuidInput"
                        :disabled="locked"
                    />
                </div>

                <!-- Decision -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                        Tu decisión *
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <button type="button" :disabled="locked" @click="decision = 'Aceptacion'" :class="decisionCardClass('Aceptacion')">
                            <div class="flex items-center gap-2.5">
                                <span class="w-9 h-9 rounded-full bg-emerald-500/15 text-emerald-500 flex items-center justify-center flex-shrink-0">
                                    <i class="pi pi-check !text-sm"></i>
                                </span>
                                <span class="text-sm font-semibold text-gray-900 dark:text-white">Aceptar</span>
                            </div>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 m-0 mt-2 leading-relaxed">
                                La factura se cancela definitivamente ante el SAT.
                            </p>
                        </button>
                        <button type="button" :disabled="locked" @click="decision = 'Rechazo'" :class="decisionCardClass('Rechazo')">
                            <div class="flex items-center gap-2.5">
                                <span class="w-9 h-9 rounded-full bg-red-500/15 text-red-500 flex items-center justify-center flex-shrink-0">
                                    <i class="pi pi-times !text-sm"></i>
                                </span>
                                <span class="text-sm font-semibold text-gray-900 dark:text-white">Rechazar</span>
                            </div>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 m-0 mt-2 leading-relaxed">
                                La factura sigue vigente y podrás deducirla.
                            </p>
                        </button>
                    </div>
                </div>

                <!-- Error -->
                <Message
                    v-if="errorMessage"
                    severity="error"
                    variant="simple"
                    size="small"
                >
                    {{ errorMessage }}
                </Message>

                <!-- Info legal -->
                <div class="flex items-start gap-2.5 px-4 py-3 rounded-2xl bg-blue-50/60 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-900/20">
                    <i class="pi pi-info-circle !text-xs text-blue-500 mt-0.5"></i>
                    <p class="text-[12px] text-blue-700 dark:text-blue-300 m-0 leading-relaxed">
                        El proveedor solicitó cancelar este CFDI. Acepta únicamente si la operación no se realizó, se canceló el pedido o el proveedor reexpedirá la factura. Rechaza si la operación sí se concretó y requieres respaldar el gasto ante el SAT. Nota: Una vez aceptada, la cancelación ante el SAT es definitiva e irreversible.
                    </p>
                </div>
            </div>

            <!-- ═══════════ HISTORIAL ═══════════ -->
            <div v-else class="space-y-4">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-xs text-gray-500 dark:text-gray-400 m-0">
                        Respuestas enviadas a solicitudes de cancelación de tus proveedores.
                    </p>
                    <i v-if="historyLoading" class="pi pi-spin pi-spinner !text-sm text-gray-400"></i>
                </div>

                <Message
                    v-if="historyError"
                    severity="error"
                    variant="simple"
                    size="small"
                >
                    {{ historyError }}
                </Message>

                <div class="overflow-x-auto">
                <DataTable
                    :value="history"
                    tableStyle="min-width: 34rem"
                    :pt="dataTablePt"
                >
                    <Column header="Fecha">
                        <template #body="{ data }">
                            <span class="text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                {{ formatDate(data.responded_at || data.created_at) }}
                            </span>
                        </template>
                    </Column>
                    <Column header="RFC">
                        <template #body="{ data }">
                            <span class="font-mono text-xs text-gray-700 dark:text-gray-300">{{ data.rfc }}</span>
                        </template>
                    </Column>
                    <Column header="UUID">
                        <template #body="{ data }">
                            <span class="font-mono text-xs text-gray-500 dark:text-gray-400" :title="data.uuid">
                                {{ truncateUuid(data.uuid) }}
                            </span>
                        </template>
                    </Column>
                    <Column header="Resultado">
                        <template #body="{ data }">
                            <Tag
                                :value="resultLabel(data.action)"
                                :severity="resultSeverity(data.action)"
                                :pt="tagPt"
                            />
                        </template>
                    </Column>
                    <template #empty>
                        <div class="flex flex-col items-center justify-center py-10 text-center">
                            <i class="pi pi-inbox !text-3xl text-gray-300 dark:text-gray-600 mb-3"></i>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Aún no has respondido ninguna solicitud de cancelación.</p>
                        </div>
                    </template>
                </DataTable>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex flex-col sm:flex-row justify-end items-stretch sm:items-center gap-3 w-full mt-4 pt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
                <Button
                    label="Cerrar"
                    text
                    @click="close"
                    :disabled="submitting"
                    class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold !justify-center w-full sm:w-auto"
                />
                <Button
                    v-if="activeTab === 'responder' && !result"
                    :label="submitLabel"
                    :icon="submitIcon"
                    :severity="decision === 'Rechazo' ? 'danger' : 'success'"
                    :loading="submitting"
                    @click="submit"
                    class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-6 shadow-sm !justify-center w-full sm:w-auto"
                />
            </div>
        </template>
    </Dialog>
</template>
