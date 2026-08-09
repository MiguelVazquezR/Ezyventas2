<script setup>
import PagoDetailSection from './Sections/PagoDetailSection.vue';

defineProps({
    form: { type: Object, required: true },
    ppdInvoices: { type: [Array, Object], default: () => [] },
});
</script>

<template>
    <!-- CFDI de Pago (P): complemento de pago 2.0 -->
    <PagoDetailSection :form="form" :ppd-invoices="ppdInvoices" />
</template>
