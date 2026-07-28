<script setup>
defineProps({
    subtotal:       { type: Number, required: true },
    ivaTrasladado:  { type: Number, required: true },
    isrRetenido:    { type: Number, required: true },
    ivaRetenido:    { type: Number, required: true },
    granTotal:      { type: Number, required: true },
    retentionApplies: { type: Boolean, default: false },
    isResico:       { type: Boolean, default: false },
});

const formatCurrency = (value) =>
    new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
</script>

<template>
    <div class="rounded-3xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 p-6 lg:p-8 flex flex-col shadow-lg shadow-zinc-200/20 dark:shadow-black/40">
        <!-- Header -->
        <div class="mb-6 flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center flex-shrink-0 border border-emerald-100 dark:border-emerald-900/30">
                <i class="pi pi-calculator !text-sm text-emerald-500"></i>
            </div>
            <div>
                <h2 class="text-xs font-bold text-zinc-400 dark:text-zinc-500 tracking-widest uppercase m-0">Desglose financiero</h2>
                <p class="text-[10px] text-zinc-500 uppercase tracking-widest mt-1 m-0">Subtotal, impuestos y retenciones</p>
            </div>
        </div>

        <!-- ── Subtotal ── -->
        <div class="flex items-center justify-between py-3 border-b border-zinc-200 dark:border-zinc-800">
            <span class="text-sm font-light text-zinc-500 dark:text-zinc-400">Subtotal</span>
            <span class="text-lg font-light tracking-tight text-zinc-900 dark:text-white">
                {{ formatCurrency(subtotal) }}
            </span>
        </div>

        <!-- ── IVA Trasladado ── -->
        <div v-if="ivaTrasladado > 0" class="flex items-center justify-between py-3 border-b border-zinc-200 dark:border-zinc-800">
            <span class="text-sm font-light text-zinc-500 dark:text-zinc-400">IVA trasladado</span>
            <span class="text-lg font-light tracking-tight text-zinc-900 dark:text-white">
                {{ formatCurrency(ivaTrasladado) }}
            </span>
        </div>

        <!-- ── Retention banner ── -->
        <div v-if="retentionApplies" class="flex items-start gap-2.5 px-4 py-3 my-2 rounded-2xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/40">
            <i class="pi pi-info-circle !text-sm text-amber-500 mt-px shrink-0"></i>
            <div class="flex flex-col gap-0.5">
                <span class="text-xs text-amber-700 dark:text-amber-300 font-medium leading-relaxed">
                    Aplican retenciones — Emisor Persona Física → Receptor Persona Moral
                </span>
                <span v-if="isResico" class="text-[10px] uppercase tracking-widest text-amber-600 dark:text-amber-300/80 font-bold">
                    Régimen RESICO (626)
                </span>
            </div>
        </div>

        <!-- ── ISR Retenido ── -->
        <div v-if="isrRetenido > 0" class="flex items-center justify-between py-3 border-b border-red-100 dark:border-red-900/20">
            <span class="text-sm font-light text-red-500 dark:text-red-400">ISR retenido</span>
            <span class="text-lg font-light tracking-tight text-red-500 dark:text-red-400">
                − {{ formatCurrency(isrRetenido) }}
            </span>
        </div>

        <!-- ── IVA Retenido ── -->
        <div v-if="ivaRetenido > 0" class="flex items-center justify-between py-3 border-b border-red-100 dark:border-red-900/20">
            <span class="text-sm font-light text-red-500 dark:text-red-400">IVA retenido</span>
            <span class="text-lg font-light tracking-tight text-red-500 dark:text-red-400">
                − {{ formatCurrency(ivaRetenido) }}
            </span>
        </div>

        <!-- ── Gran Total ── -->
        <div class="flex items-center justify-between pt-5 mt-1">
            <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Total</span>
            <span class="text-3xl font-light tracking-tight text-emerald-500 dark:text-emerald-400">
                {{ formatCurrency(granTotal) }}
            </span>
        </div>
    </div>
</template>
