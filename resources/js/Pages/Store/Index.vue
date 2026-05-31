<script setup>
import { ref, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import StoreLayout from '@/Layouts/StoreLayout.vue';
import { useDebounceFn } from '@vueuse/core';

const page = usePage();
const store = computed(() => page.props.store || {});

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
    const slug = window.location.pathname.split('/')[2];
    router.get(route('store.product.show', { slug, product: product.id }));
};

const pageTitle = computed(() => store.value.name || 'Tienda');
</script>

<template>
    <Head :title="pageTitle" />
    <StoreLayout>
        <div class="max-w-7xl mx-auto px-4 md:px-6 py-8">
            <!-- Welcome message -->
            <div v-if="store.welcome_message" class="mb-8 text-center">
                <p class="text-xl md:text-2xl font-light tracking-tight text-gray-800 dark:text-white m-0">{{ store.welcome_message }}</p>
            </div>

            <!-- Search -->
            <div class="mb-10">
                <IconField iconPosition="left" class="w-full max-w-lg mx-auto">
                    <InputIcon class="pi pi-search !text-sm text-gray-400 dark:text-gray-500" />
                    <InputText v-model="search" @input="onSearch" placeholder="Buscar productos..."
                        class="w-full"
                        :pt="{
                            root: { class: '!rounded-2xl !py-3 !bg-white dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a] !text-gray-900 dark:!text-white !text-sm focus:!border-gray-300 dark:focus:!border-gray-600 transition-colors' }
                        }" />
                </IconField>
            </div>

            <div class="flex flex-col md:flex-row gap-8">
                <!-- Category sidebar -->
                <div v-if="categories.length > 0" class="md:w-52 shrink-0">
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 m-0 mb-3">Categorías</p>
                    <div class="flex flex-wrap md:flex-col gap-1.5">
                        <button v-for="cat in categories" :key="cat.id"
                            class="text-left px-4 py-2 text-xs font-medium rounded-full transition-all duration-200 border"
                            :class="activeCategory === String(cat.id)
                                ? 'text-white border-transparent'
                                : 'text-gray-600 dark:text-gray-400 bg-white dark:bg-[#232323] border-gray-100 dark:border-[#3a3a3a] hover:border-gray-300 dark:hover:border-gray-600'"
                            :style="activeCategory === String(cat.id) ? { background: 'var(--store-primary)', borderColor: 'var(--store-primary)' } : {}"
                            @click="filterCategory(String(cat.id))">
                            {{ cat.name }}
                        </button>
                    </div>
                </div>

                <!-- Product grid -->
                <div class="flex-1 min-w-0">
                    <!-- Results count -->
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 m-0 mb-4">
                        {{ products.total }} {{ products.total === 1 ? 'producto' : 'productos' }}
                    </p>

                    <DataView :value="products.data" layout="grid" :pt="{ content: { class: '!bg-transparent !p-0' } }">
                        <template #grid="slotProps">
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                                <div v-for="item in slotProps.items" :key="item.id"
                                    class="bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] overflow-hidden cursor-pointer hover:border-gray-300 dark:hover:border-gray-600 transition-all duration-300 group"
                                    @click="goToProduct(item)">
                                    <!-- Image -->
                                    <div class="h-52 flex items-center justify-center p-6 bg-gray-50 dark:bg-[#1a1a1a]">
                                        <img v-if="item.media?.length" :src="item.media[0].original_url"
                                            class="max-h-full max-w-full object-contain transition-transform duration-500 group-hover:scale-105" />
                                        <i v-else class="pi pi-image !text-4xl text-gray-300 dark:text-gray-600" />
                                    </div>
                                    <!-- Info -->
                                    <div class="p-4">
                                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 m-0 mb-1">
                                            {{ item.category?.name || '' }}
                                        </p>
                                        <h3 class="font-medium text-gray-900 dark:text-white text-sm line-clamp-2 m-0 leading-snug">
                                            {{ item.name }}
                                        </h3>
                                        <div class="flex items-baseline gap-1 mt-3">
                                            <span class="text-lg font-light tracking-tight text-gray-900 dark:text-white"
                                                :style="{ color: 'var(--store-primary)' }">
                                                {{ formatCurrency(item.online_price || item.selling_price).replace(/\.00$/, '') }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </DataView>

                    <!-- Pagination -->
                    <div v-if="products.total > products.per_page" class="mt-10 flex justify-center">
                        <Paginator :rows="products.per_page" :totalRecords="products.total"
                            :first="(products.current_page - 1) * products.per_page"
                            @page="onPageChange"
                            :pt="{
                                root: { class: '!bg-transparent' },
                                pageButton: ({ context }) => ({
                                    class: context.active
                                        ? '!rounded-xl !text-white'
                                        : '!rounded-xl !bg-white dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a] !text-gray-600 dark:!text-gray-400'
                                }),
                            }"
                            :style="{ '--p-paginator-page-button-active-background': 'var(--store-primary)' }" />
                    </div>

                    <!-- Empty state -->
                    <div v-if="products.data.length === 0" class="text-center py-20">
                        <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-gray-100 dark:bg-[#232323] flex items-center justify-center">
                            <i class="pi pi-search !text-2xl text-gray-300 dark:text-gray-600" />
                        </div>
                        <p class="text-sm text-gray-500 dark:text-gray-400 m-0">No se encontraron productos.</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1 m-0">Intenta con otra búsqueda o categoría.</p>
                    </div>
                </div>
            </div>
        </div>
    </StoreLayout>
</template>
