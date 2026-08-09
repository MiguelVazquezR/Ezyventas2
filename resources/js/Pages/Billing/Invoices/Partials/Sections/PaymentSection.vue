<script setup>
import { paymentFormOptions, paymentMethodOptions } from '../../satCatalogs';
import { selectPt, inputNumberPt } from '../../ptConfigs';
import SectionCard from '@/Components/Billing/SectionCard.vue';

defineProps({
    form: { type: Object, required: true },
});
</script>

<template>
    <!-- ═══ Forma y método de pago ═══ -->
    <SectionCard id="forma-pago" icon="pi pi-credit-card" title="Forma y método de pago" subtitle="Condiciones de la factura">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Forma de pago *</label><Select v-model="form.payment_form" :options="paymentFormOptions" optionLabel="label" optionValue="value" placeholder="Selecciona" class="w-full" :pt="selectPt" /><Message v-if="form.errors.payment_form" severity="error" variant="simple" size="small">{{ form.errors.payment_form }}</Message></div>
            <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Método de pago *</label><Select v-model="form.payment_method" :options="paymentMethodOptions" optionLabel="label" optionValue="value" placeholder="Selecciona" class="w-full" :pt="selectPt" /><Message v-if="form.errors.payment_method" severity="error" variant="simple" size="small">{{ form.errors.payment_method }}</Message></div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Moneda</label><Select v-model="form.currency" :options="[{ label: 'MXN - Peso mexicano', value: 'MXN' }, { label: 'USD - Dólar estadounidense', value: 'USD' }]" optionLabel="label" optionValue="value" class="w-full" :pt="selectPt" /></div>
            <div v-if="form.currency !== 'MXN'" class="flex flex-col gap-1.5"><label class="text-[10px] uppercase tracking-widest font-bold text-slate-500 dark:text-neutral-500 m-0">Tipo de cambio *</label><InputNumber v-model="form.exchange_rate" placeholder="17.45" :minFractionDigits="2" :maxFractionDigits="2" :min="0.01" locale="es-MX" class="w-full" :pt="inputNumberPt" /><Message v-if="form.errors.exchange_rate" severity="error" variant="simple" size="small">{{ form.errors.exchange_rate }}</Message><p class="text-[10px] text-slate-400 dark:text-neutral-500 m-0">Requerido por el SAT (Anexo 20). Agrega el tipo de cambio al momento de la factura.</p></div>
        </div>
    </SectionCard>
</template>
