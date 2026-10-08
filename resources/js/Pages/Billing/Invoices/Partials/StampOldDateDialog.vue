<script setup>
/**
 * Modal ÚNICO que se muestra al intentar timbrar una prefactura cuya fecha
 * de emisión tiene más de 72 horas (regla del SAT). Se usa en Index, Show y
 * la edición de la prefactura, así los cambios aplican en todos lados.
 *
 * Ofrece dos caminos:
 *  1. "Editar fecha" → manda a la edición de la prefactura para corregir la
 *     fecha de emisión a una válida.
 *  2. "Timbrar con fecha de hoy" → re-emite el CFDI con la fecha/hora actual.
 * Se cierra con la X o haciendo clic fuera.
 */
defineProps({
    visible: { type: Boolean, default: false },
});

const emit = defineEmits(['update:visible', 'stamp-today', 'edit']);
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        :dismissableMask="true"
        header="Fecha de emisión vencida"
        class="!w-full !max-w-md"
        @update:visible="(value) => emit('update:visible', value)"
        :pt="{
            root: { class: '!rounded-3xl' },
            header: { class: '!px-6 !pt-5 !pb-0' },
            content: { class: '!px-6 !pt-5 !pb-2' },
            footer: { class: '!px-6 !pb-5 !pt-4' },
        }"
    >
        <div class="flex items-start gap-3">
            <i class="pi pi-exclamation-triangle !text-xl text-amber-500 shrink-0 mt-0.5"></i>
            <div>
                <p class="text-sm text-slate-700 dark:text-neutral-200 leading-relaxed m-0">
                    Han pasado más de 72 horas desde la fecha de emisión de esta prefactura. El SAT ya no permite timbrar un comprobante con esa fecha.
                </p>
                <p class="text-xs text-slate-400 dark:text-neutral-500 mt-3 m-0 leading-relaxed">
                    Elige timbrar con la fecha y hora de hoy, o edita la fecha de emisión de la prefactura a una fecha válida (máximo 72 horas).
                </p>
            </div>
        </div>

        <template #footer>
            <div class="flex flex-wrap justify-end gap-2">
                <Button label="Editar fecha" icon="pi pi-pencil" severity="secondary" outlined class="!rounded-full !uppercase !tracking-widest !text-xs !font-bold" @click="emit('edit')" />
                <Button label="Timbrar con fecha de hoy" icon="pi pi-check-circle" severity="warning" class="!rounded-full !uppercase !tracking-widest !text-xs !font-bold" @click="emit('stamp-today')" />
            </div>
        </template>
    </Dialog>
</template>
