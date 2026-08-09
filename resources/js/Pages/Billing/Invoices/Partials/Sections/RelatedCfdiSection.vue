<script setup>
import { tipoRelacionOptions } from '../../satCatalogs';
import { inputPt, selectPt } from '../../ptConfigs';
import SectionCard from '@/Components/Billing/SectionCard.vue';

const props = defineProps({
    form: { type: Object, required: true },
});

const addRelatedUuid = () => props.form.cfdi_relacionados.push('');
const removeRelatedUuid = (index) => props.form.cfdi_relacionados.splice(index, 1);
</script>

<template>
    <!-- ═══ CFDI relacionados (Nota de crédito) ═══ -->
    <SectionCard id="cfdi-relacionados" icon="pi pi-link" title="CFDI relacionados" subtitle="Requerido por el SAT en notas de crédito">

        <div class="flex flex-col gap-1.5">
            <label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Tipo de relación *</label>
            <Select v-model="form.tipo_relacion" :options="tipoRelacionOptions" optionLabel="label" optionValue="value" placeholder="Selecciona el tipo de relación" class="w-full" :pt="selectPt" />
            <Message v-if="form.errors.tipo_relacion" severity="error" variant="simple" size="small">{{ form.errors.tipo_relacion }}</Message>
        </div>

        <div class="flex items-center justify-between">
            <label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">UUID de las facturas relacionadas *</label>
            <Button type="button" icon="pi pi-plus" label="Agregar UUID" severity="secondary" text size="small" @click="addRelatedUuid" class="!rounded-full !px-5 !py-2 !text-xs !font-semibold !tracking-wider !uppercase !transition-all !duration-200 active:scale-95" />
        </div>

        <div v-if="form.cfdi_relacionados.length === 0" class="rounded-2xl border-2 border-dashed border-slate-200 dark:border-neutral-800 py-8 text-center">
            <i class="pi pi-hashtag !text-2xl text-slate-300 dark:text-neutral-600 mb-2 block"></i>
            <p class="text-xs text-slate-400 dark:text-neutral-500 m-0">Agrega al menos un UUID de la factura que se relaciona</p>
        </div>

        <div v-else class="space-y-3">
            <div v-for="(uuid, index) in form.cfdi_relacionados" :key="index" class="flex items-center gap-3">
                <div class="flex flex-col gap-1 flex-1">
                    <InputText v-model="form.cfdi_relacionados[index]" placeholder="UUID (ej. 123e4567-e89b-12d3-a456-426614174000)" class="w-full lowercase" :pt="inputPt" />
                    <Message v-if="form.errors[`cfdi_relacionados.${index}`]" severity="error" variant="simple" size="small">{{ form.errors[`cfdi_relacionados.${index}`] }}</Message>
                </div>
                <Button type="button" icon="pi pi-trash" severity="danger" text rounded size="small" @click="removeRelatedUuid(index)" v-tooltip.top="'Eliminar UUID'" />
            </div>
        </div>
    </SectionCard>
</template>
