<script setup>
import PaymentSection from './Sections/PaymentSection.vue';
import ConceptsSection from './Sections/ConceptsSection.vue';
import InvoiceTotals from './InvoiceTotals.vue';

defineProps({
    form: { type: Object, required: true },
    products: { type: [Array, Object], default: () => [] },
    services: { type: [Array, Object], default: () => [] },
    // Totals (computed by useInvoiceTaxes in InvoiceForm)
    subtotal: { type: Number, default: 0 },
    ivaTrasladado: { type: Number, default: 0 },
    isrRetenido: { type: Number, default: 0 },
    ivaRetenido: { type: Number, default: 0 },
    granTotal: { type: Number, default: 0 },
    retentionApplies: { type: Boolean, default: false },
    isResico: { type: Boolean, default: false },
    retentionMessage: { type: String, default: null },
    // Total de la venta relacionada (aviso de diferencia en el desglose).
    saleTotal: { type: Number, default: null },
});
</script>

<template>
    <!-- CFDI de Ingreso (I): factura estándar -->
    <PaymentSection :form="form" />
    <ConceptsSection :form="form" :products="products" :services="services" :is-traslado="false" />
    <InvoiceTotals
        :subtotal="subtotal"
        :iva-trasladado="ivaTrasladado"
        :isr-retenido="isrRetenido"
        :iva-retenido="ivaRetenido"
        :gran-total="granTotal"
        :sale-total="saleTotal"
        :retention-applies="retentionApplies"
        :is-resico="isResico"
        :retention-message="retentionMessage"
    />
</template>
