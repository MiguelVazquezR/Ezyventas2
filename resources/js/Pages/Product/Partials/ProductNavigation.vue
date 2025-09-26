<script setup>
import { ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();

const currentView = ref(
    page.component.startsWith('Product/BaseCatalog') ? 'base_catalog' : 'my_products'
);

const productTypeOptions = ref([
    { label: 'Mis Productos', value: 'my_products', route: 'products.index' },
    { label: 'Catálogo Base', value: 'base_catalog', route: 'products.base-catalog.index' }
]);
</script>

<template>
    <div class="mb-4">
        <SelectButton v-model="currentView" :options="productTypeOptions" optionLabel="label" optionValue="value"
            @change="$inertia.get(route($event.value === 'my_products' ? 'products.index' : 'products.base-catalog.index'))" />
    </div>
</template>
