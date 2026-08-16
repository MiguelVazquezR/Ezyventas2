<script setup>
import ConceptsSection from './Sections/ConceptsSection.vue';

defineProps({
    form: { type: Object, required: true },
    products: { type: [Array, Object], default: () => [] },
    services: { type: [Array, Object], default: () => [] },
});
</script>

<template>
    <!-- Carta porte / Traslado (T): conceptos sin importes ni impuestos -->
    <ConceptsSection :form="form" :products="products" :services="services" :is-traslado="true" />
</template>
