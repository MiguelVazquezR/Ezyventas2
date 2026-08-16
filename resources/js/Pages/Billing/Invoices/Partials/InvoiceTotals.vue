<script setup>
import SectionCard from '@/Components/Billing/SectionCard.vue';

defineProps({
    subtotal:       { type: Number, required: true },
    ivaTrasladado:  { type: Number, required: true },
    isrRetenido:    { type: Number, required: true },
    ivaRetenido:    { type: Number, required: true },
    granTotal:      { type: Number, required: true },
    retentionApplies: { type: Boolean, default: false },
    isResico:       { type: Boolean, default: false },
    retentionMessage: { type: String, default: null },
});

const formatCurrency = (value) =>
    new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
</script>

<template>
    <SectionCard id="pago" icon="pi pi-calculator" title="Desglose financiero" subtitle="Subtotal, impuestos y retenciones">
        <div class="flex flex-col">
            <!-- ── Subtotal ── -->
            <div class="flex items-center justify-between py-3 border-b border-slate-100 dark:border-neutral-800">
                <span class="text-sm font-light text-slate-500 dark:text-neutral-400">Subtotal</span>
                <span class="text-lg font-light tracking-tight text-slate-900 dark:text-white">
                    {{ formatCurrency(subtotal) }}
                </span>
            </div>

            <!-- ── IVA Trasladado ── -->
            <div v-if="ivaTrasladado > 0" class="flex items-center justify-between py-3 border-b border-slate-100 dark:border-neutral-800">
                <span class="text-sm font-light text-slate-500 dark:text-neutral-400">IVA trasladado</span>
                <span class="text-lg font-light tracking-tight text-slate-900 dark:text-white">
                    {{ formatCurrency(ivaTrasladado) }}
                </span>
            </div>

            <!-- ── Retention banner ── -->
            <div v-if="retentionApplies" class="flex items-start gap-2.5 px-4 py-3 my-2 rounded-2xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/40">
                <i class="pi pi-info-circle !text-sm text-amber-500 mt-px shrink-0"></i>
                <div class="flex flex-col gap-0.5">
                    <span class="text-xs text-amber-700 dark:text-amber-300 font-medium leading-relaxed">
                        {{ retentionMessage || 'Aplican retenciones — Emisor Persona Física → Receptor Persona Moral.' }}
                    </span>
                    <span v-if="isResico" class="text-[10px] uppercase tracking-widest text-amber-600 dark:text-amber-300/80 font-bold">
                        Régimen RESICO (626) — Retención ISR 1.25 %
                    </span>
                </div>
            </div>

            <!-- ── ISR Retenido ── -->
            <div v-if="isrRetenido > 0" class="flex items-center justify-between py-3 border-b border-amber-100 dark:border-amber-900/20">
                <span class="text-sm font-light text-amber-500 dark:text-amber-400">ISR retenido</span>
                <span class="text-lg font-light tracking-tight text-amber-500 dark:text-amber-400">
                    − {{ formatCurrency(isrRetenido) }}
                </span>
            </div>

            <!-- ── IVA Retenido ── -->
            <div v-if="ivaRetenido > 0" class="flex items-center justify-between py-3 border-b border-amber-100 dark:border-amber-900/20">
                <span class="text-sm font-light text-amber-500 dark:text-amber-400">IVA retenido</span>
                <span class="text-lg font-light tracking-tight text-amber-500 dark:text-amber-400">
                    − {{ formatCurrency(ivaRetenido) }}
                </span>
            </div>

            <!-- ── Gran Total ── -->
            <div class="flex items-center justify-between pt-5 mt-1">
                <span class="text-sm font-semibold text-slate-700 dark:text-neutral-300">Total</span>
                <span class="text-3xl font-light tracking-tight text-emerald-500 dark:text-emerald-400">
                    {{ formatCurrency(granTotal) }}
                </span>
            </div>
        </div>
    </SectionCard>
</template>
