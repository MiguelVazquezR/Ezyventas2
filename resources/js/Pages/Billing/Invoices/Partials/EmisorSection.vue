<script setup>
import { ref, computed, watch } from 'vue';
import { comprobanteTypeOptions, exportacionOptions, getComprobanteTypeLabel, getExportacionLabel, getRegimeLabel } from '../satCatalogs';
import { selectPt } from '../ptConfigs';
import SectionCard from '@/Components/Billing/SectionCard.vue';
import InfoField from '@/Components/Billing/InfoField.vue';

// v-model: the currently selected fiscal profile object.
const model = defineModel({ type: Object, default: null });

const props = defineProps({
    form: { type: Object, required: true },
    fiscalProfiles: { type: Array, default: () => [] },
    mode: { type: String, required: true }, // 'create' | 'edit'
    isNomina: { type: Boolean, default: false },
    showExportacionSelector: { type: Boolean, default: true },
    readinessMessage: { type: String, default: null },
    profileSettingsUrl: { type: String, default: '#' },
});

// ── Emitter regime / postal code derived from the selected profile ──
const emitterRegime = ref('');
const emitterPostalCode = ref('');

watch(model, (profile) => {
    emitterRegime.value = profile?.regimen_fiscal || '';
    emitterPostalCode.value = profile?.postal_code || '';
}, { immediate: true });

// "601 - General de Ley Personas Morales" (clave + nombre del catálogo SAT)
const emitterRegimeLabel = computed(() => getRegimeLabel(emitterRegime.value));
</script>

<template>
    <!-- ═══ Emisor ═══ -->
    <SectionCard id="emisor" icon="pi pi-building" title="Emisor" subtitle="RFC de la persona física o empresa que emitirá la factura">

        <div class="flex flex-col gap-1.5" v-if="fiscalProfiles.length > 1">
            <label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Datos del emisor *</label>
            <Select v-model="model" :options="fiscalProfiles" optionLabel="razon_social" placeholder="Selecciona el RFC emisor" class="w-full" :pt="selectPt">
                <template #value="s"><div v-if="s.value" class="flex items-center gap-2"><span class="text-sm font-medium">{{ s.value.rfc }}</span><span class="text-slate-400">-</span><span class="text-sm">{{ s.value.razon_social }}</span></div></template>
                <template #option="s"><div class="flex flex-col gap-0.5"><span class="text-sm font-medium">{{ s.option.rfc }}</span><span class="text-xs text-slate-500 dark:text-neutral-400">{{ s.option.razon_social }}</span></div></template>
            </Select>
            <Message v-if="form.errors.fiscal_profile_id" severity="error" variant="simple" size="small">{{ form.errors.fiscal_profile_id }}</Message>
        </div>

        <div v-if="model" class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <InfoField label="RFC" :value="model.rfc" icon="pi pi-id-card" />
            <InfoField label="Razón social" :value="model.razon_social" icon="pi pi-briefcase" />
            <InfoField label="Régimen fiscal" :value="emitterRegimeLabel" icon="pi pi-tag" />
            <InfoField label="C. P." :value="emitterPostalCode" icon="pi pi-map-marker" />
        </div>

        <!-- ═══ PAC readiness warning (below emitter data) ═══ -->
        <div v-if="mode === 'create' && readinessMessage" class="flex items-start gap-2.5 px-4 py-3 rounded-2xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/40">
            <i class="pi pi-exclamation-triangle !text-sm text-amber-500 mt-px shrink-0"></i>
            <div class="flex flex-col gap-1">
                <span class="text-xs text-amber-700 dark:text-amber-300 font-medium leading-relaxed">
                    {{ readinessMessage }}
                </span>
                <a :href="profileSettingsUrl" class="text-[11px] text-primary dark:text-primary-500 font-medium underline">Completar configuración del RFC emisor</a>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Tipo de comprobante *</label>
                <Select
                    v-model="form.tipo_comprobante"
                    :options="comprobanteTypeOptions"
                    optionLabel="label"
                    optionValue="value"
                    optionDisabled="disabled"
                    placeholder="Selecciona el tipo de comprobante"
                    filter
                    class="w-full"
                    :pt="selectPt"
                >
                    <template #value="s">
                        <span v-if="s.value" class="text-sm font-medium">{{ getComprobanteTypeLabel(s.value) }}</span>
                    </template>
                    <template #option="s">
                        <div class="flex items-center justify-between gap-3 py-0.5 w-full">
                            <span class="text-sm font-medium shrink-0">{{ s.option.label }}</span>
                            <span class="text-[10px] text-slate-500 dark:text-neutral-400 leading-tight text-right min-w-0">{{ s.option.description }}</span>
                        </div>
                    </template>
                </Select>
                <Message v-if="form.errors.tipo_comprobante" severity="error" variant="simple" size="small">{{ form.errors.tipo_comprobante }}</Message>
                <Message v-if="isNomina" severity="info" variant="simple" size="small">
                    La nómina debe gestionarse desde el módulo especializado de Recursos Humanos / Nómina
                </Message>
            </div>

            <div v-if="showExportacionSelector" class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Exportación</label>
                <Select
                    v-model="form.exportacion"
                    :options="exportacionOptions"
                    optionLabel="label"
                    optionValue="value"
                    placeholder="Selecciona"
                    filter
                    class="w-full"
                    :pt="selectPt"
                >
                    <template #value="s">
                        <span v-if="s.value" class="text-sm font-medium">{{ getExportacionLabel(s.value) }}</span>
                    </template>
                    <template #option="s">
                        <span class="text-sm font-medium">{{ s.option.label }}</span>
                    </template>
                </Select>
                <Message v-if="form.errors.exportacion" severity="error" variant="simple" size="small">{{ form.errors.exportacion }}</Message>
            </div>
            <InfoField v-else label="Exportación" value="01 - No aplica" icon="pi pi-globe" hint="En un CFDI de pago la exportación siempre es &quot;01 - No aplica&quot; (regla SAT)." />
        </div>
    </SectionCard>
</template>
