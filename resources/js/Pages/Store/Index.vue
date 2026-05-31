<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import StoreLayout from '@/Layouts/StoreLayout.vue';
import InputText from 'primevue/inputtext';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import Button from 'primevue/button';
import DataView from 'primevue/dataview';
import Paginator from 'primevue/paginator';
import { useDebounceFn } from '@vueuse/core';

const props = defineProps({
    products: Object,
    categories: Array,
    filters: Object,
});

const search = ref(props.filters.search || '');
const activeCategory = ref(props.filters.category || '');

const formatCurrency = (num) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(num || 0);
};

const onSearch = useDebounceFn(() => {
    router.get(window.location.pathname, { search: search.value, category: activeCategory.value || undefined }, { preserveState: true, preserveScroll: true, replace: true });
}, 400);

const filterCategory = (cat) => {
    activeCategory.value = activeCategory.value === cat ? '' : cat;
    router.get(window.location.pathname, { search: search.value, category: activeCategory.value || undefined }, { preserveState: true, preserveScroll: true, replace: true });
};

const onPageChange = (event) => {
    router.get(window.location.pathname, { page: event.page + 1, search: search.value, category: activeCategory.value || undefined }, { preserveState: true, preserveScroll: true, replace: true });
};

const goToProduct = (product) => {
    router.get(`${window.location.pathname}/product/${product.id}`);
};
</script>

<template>
    <Head title="Tienda" />
    <StoreLayout>
        <div class="max-w-6xl mx-auto px-4 py-8">
            <!-- Search -->
            <div class="mb-8">
                <IconField iconPosition="left" class="w-full max-w-md mx-auto">
                    <InputIcon class="pi pi-search !text-sm text-gray-400" />
                    <InputText v-model="search" @input="onSearch" placeholder="Buscar productos..." class="w-full !rounded-2xl !py-3 !bg-white !border-gray-200" />
                </IconField>
            </div>

            <div class="flex flex-col md:flex-row gap-6">
                <!-- Category sidebar -->
                <div v-if="categories.length > 0" class="md:w-56 shrink-0">
                    <div class="flex flex-wrap md:flex-col gap-2">
                        <Button v-for="cat in categories" :key="cat.id" :label="cat.name" :severity="activeCategory === String(cat.id) ? 'primary' : 'secondary'" :outlined="activeCategory !== String(cat.id)" size="small" class="!rounded-full !text-xs" @click="filterCategory(String(cat.id))" />
                    </div>
                </div>

                <!-- Product grid -->
                <div class="flex-1">
                    <DataView :value="products.data" layout="grid" :pt="{ content: { class: '!bg-transparent' } }">
                        <template #grid="slotProps">
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                                <div v-for="item in slotProps.items" :key="item.id"
                                    class="bg-white rounded-2xl border border-gray-200 overflow-hidden cursor-pointer hover:shadow-md transition-shadow group"
                                    @click="goToProduct(item)">
                                    <!-- Image -->
                                    <div class="h-48 flex items-center justify-center p-4 bg-gray-50">
                                        <img v-if="item.media?.length" :src="item.media[0].original_url" class="max-h-full max-w-full object-contain" />
                                        <i v-else class="pi pi-image !text-4xl text-gray-300" />
                                    </div>
                                    <!-- Info -->
                                    <div class="p-4">
                                        <p class="text-xs text-gray-400 uppercase tracking-widest font-bold m-0">{{ item.category?.name || '' }}</p>
                                        <h3 class="font-medium text-gray-900 text-sm mt-1 line-clamp-2 m-0">{{ item.name }}</h3>
                                        <p class="text-lg font-bold mt-2 m-0" style="color: var(--store-primary)">{{ formatCurrency(item.online_price || item.selling_price) }}</p>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </DataView>

                    <div v-if="products.total > products.per_page" class="mt-8 flex justify-center">
                        <Paginator :rows="products.per_page" :totalRecords="products.total" :first="(products.current_page - 1) * products.per_page" @page="onPageChange" />
                    </div>

                    <div v-if="products.data.length === 0" class="text-center py-16">
                        <i class="pi pi-search !text-4xl text-gray-300 mb-4 block" />
                        <p class="text-gray-500">No se encontraron productos.</p>
                    </div>
                </div>
            </div>
        </div>
    </StoreLayout>
</template>
