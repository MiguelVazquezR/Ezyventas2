<script setup>
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    invoice: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['success']);

const visible = ref(false);

const cancelForm = useForm({
    cancellation_reason: '',
    substitution_uuid: '',
});

const cancelReasons = [
    { label: '01 - Comprobante emitido con errores con relación', value: '01' },
    { label: '02 - Comprobante emitido con errores sin relación', value: '02' },
    { label: '03 - No se llevó a cabo la operación', value: '03' },
    { label: '04 - Operación nominativa relacionada en factura global', value: '04' },
];

const needsSubstitutionUuid = computed(() => cancelForm.cancellation_reason === '01');

const isOlderThan72h = computed(() => {
    if (!props.invoice.fecha_timbrado) return false;
    const timbrado = new Date(props.invoice.fecha_timbrado);
    return (new Date() - timbrado) > 72 * 60 * 60 * 1000;
});

function open() {
    cancelForm.reset();
    cancelForm.clearErrors();
    visible.value = true;
}

function submitCancel() {
    cancelForm.post(route('billing.invoices.cancel', props.invoice.id), {
        onSuccess: () => {
            visible.value = false;
            emit('success');
        },
    });
}

defineExpose({ open });

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
</script>

<template>
    <Dialog
        v-model:visible="visible"
        modal
        class="w-full max-w-lg mx-4"
        :pt="dialogPt"
    >
        <template #header>
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-full bg-red-50 dark:bg-red-900/20 text-red-500 flex items-center justify-center flex-shrink-0 border border-red-100 dark:border-red-900/30">
                    <i class="pi pi-times-circle !text-sm"></i>
                </div>
                <div>
                    <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">Solicitar cancelación de factura</h2>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">
                        Motivo de cancelación fiscal SAT
                    </p>
                </div>
            </div>
        </template>

        <div class="space-y-5 pt-2">
            <!-- 72h warning -->
            <div
                v-if="isOlderThan72h"
                class="flex items-start gap-2.5 px-4 py-3 rounded-2xl bg-amber-50/60 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-900/20"
            >
                <i class="pi pi-exclamation-triangle !text-xs text-amber-500 mt-0.5"></i>
                <p class="text-xs text-amber-700 dark:text-amber-300 m-0 leading-relaxed">
                    Esta factura tiene más de 72 horas desde que se timbró. Es probable que el SAT requiera la aprobación de tu cliente para poder cancelarla. La cancelación no será inmediata en ese caso.
                </p>
            </div>

            <p class="text-sm text-gray-500 dark:text-gray-400 m-0">
                Selecciona el motivo de cancelación fiscal según el catálogo del SAT.
                Esta acción no se puede deshacer.
            </p>

            <!-- Cancellation reason -->
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                    Motivo de cancelación *
                </label>
                <Select
                    v-model="cancelForm.cancellation_reason"
                    :options="cancelReasons"
                    optionLabel="label"
                    optionValue="value"
                    placeholder="Selecciona el motivo"
                    class="w-full"
                    :pt="selectPt"
                />
                <Message
                    v-if="cancelForm.errors.cancellation_reason"
                    severity="error"
                    variant="simple"
                    size="small"
                >
                    {{ cancelForm.errors.cancellation_reason }}
                </Message>
            </div>

            <!-- Substitution UUID -->
            <div v-if="needsSubstitutionUuid" class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                    UUID de sustitución *
                </label>
                <InputText
                    v-model="cancelForm.substitution_uuid"
                    placeholder="00000000-0000-0000-0000-000000000000"
                    class="w-full font-mono text-sm"
                    :pt="inputPt"
                />
                <Message
                    v-if="cancelForm.errors.substitution_uuid"
                    severity="error"
                    variant="simple"
                    size="small"
                >
                    {{ cancelForm.errors.substitution_uuid }}
                </Message>
                <p class="text-xs text-gray-400 dark:text-gray-500 m-0">
                    Ingresa el UUID del comprobante que sustituye a esta factura.
                </p>
            </div>

            <!-- Stamps info -->
            <div class="flex items-start gap-2.5 px-4 py-3 rounded-2xl bg-blue-50/60 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-900/20">
                <i class="pi pi-info-circle !text-xs text-blue-500 mt-0.5"></i>
                <p class="text-xs text-blue-700 dark:text-blue-300 m-0 leading-relaxed">
                    Cancelar esta factura no modifica tu saldo de timbres. El PAC cobra un timbre al timbrar, no al cancelar.
                </p>
            </div>
        </div>

        <template #footer>
            <div class="flex flex-col sm:flex-row justify-end items-stretch sm:items-center gap-3 w-full mt-4 pt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
                <Button
                    label="Cancelar"
                    text
                    @click="visible = false"
                    :disabled="cancelForm.processing"
                    class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold !justify-center w-full sm:w-auto"
                />
                <Button
                    label="Confirmar cancelación"
                    icon="pi pi-times-circle"
                    severity="danger"
                    :loading="cancelForm.processing"
                    @click="submitCancel"
                    class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-6 shadow-sm !justify-center w-full sm:w-auto"
                />
            </div>
        </template>
    </Dialog>
</template>
