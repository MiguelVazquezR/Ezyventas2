<script setup>
import { ref, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import StoreLayout from '@/Layouts/StoreLayout.vue';
import { useDebounceFn } from '@vueuse/core';

const page = usePage();
const store = computed(() => page.props.store || {});

const props = defineProps({
    products: Object,
    featured: Array,
    categories: Array,
    filters: Object,
});

const search = ref(props.filters.search || '');
const activeCategory = ref(props.filters.category || '');
const minPrice = ref(props.filters.min_price || '');
const maxPrice = ref(props.filters.max_price || '');
const sortBy = ref(props.filters.sort || '');

const formatCurrency = (num) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(num || 0);
};

const getCartItems = () => {
    try {
        return JSON.parse(sessionStorage.getItem('store_cart') || '[]');
    } catch {
        return [];
    }
};

const getCartQuantity = (productId) => {
    const item = getCartItems().find(i => i.product_id === productId);
    return item ? item.quantity : 0;
};

const doFilter = useDebounceFn(() => {
    router.get(window.location.pathname, {
        search: search.value || undefined,
        category: activeCategory.value || undefined,
        min_price: minPrice.value || undefined,
        max_price: maxPrice.value || undefined,
        sort: sortBy.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}, 400);

const onSearch = () => { doFilter(); };
const onPriceChange = () => { doFilter(); };
const onSortChange = () => { doFilter(); };

const filterCategory = (cat) => {
    activeCategory.value = activeCategory.value === cat ? '' : cat;
    doFilter();
};

const clearFilters = () => {
    search.value = '';
    activeCategory.value = '';
    minPrice.value = '';
    maxPrice.value = '';
    sortBy.value = '';
    doFilter();
};

const hasFilters = computed(() => search.value || activeCategory.value || minPrice.value || maxPrice.value || sortBy.value);

const onPageChange = (event) => {
    router.get(window.location.pathname, {
        page: event.page + 1,
        search: search.value || undefined,
        category: activeCategory.value || undefined,
        min_price: minPrice.value || undefined,
        max_price: maxPrice.value || undefined,
        sort: sortBy.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const goToProduct = (product) => {
    const slug = window.location.pathname.split('/')[2];
    router.get(route('store.product.show', { slug, product: product.id }));
};

const showBackToTop = ref(false);

const onScroll = () => {
    showBackToTop.value = window.scrollY > 500;
};

window.addEventListener('scroll', onScroll, { passive: true });

const scrollToTop = () => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

const pageTitle = computed(() => store.value.name || 'Tienda');

const getProductPrice = (item) => Number(item.online_price || item.selling_price);

const getStockInfo = (item) => {
    const available = Number(item.available_stock ?? 0);
    return {
        available,
        isOut: available <= 0,
    };
};
</script>

<template>
    <Head :title="pageTitle" />
    <StoreLayout>
        <div class="max-w-7xl mx-auto px-4 md:px-6 py-8">
            <!-- Welcome message -->
            <div v-if="store.welcome_message" class="mb-8 text-center">
                <p class="text-xl md:text-2xl font-light tracking-tight text-gray-800 dark:text-white m-0">{{ store.welcome_message }}</p>
            </div>

            <!-- Featured products -->
            <div v-if="featured && featured.length > 0" class="mb-8">
                <p class="text-[10px] uppercase tracking-widest font-bold m-0 mb-4 flex items-center gap-2">
                    <span class="w-1 h-4 rounded-full" :style="{ background: 'var(--store-secondary)' }" />
                    <span class="text-gray-400 dark:text-gray-500">Productos destacados</span>
                </p>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div v-for="item in featured" :key="item.id"
                        class="bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] overflow-hidden cursor-pointer hover:border-gray-300 dark:hover:border-gray-600 transition-all duration-300 group relative"
                        @click="goToProduct(item)">
                        <div class="h-40 flex items-center justify-center p-4 bg-gray-50 dark:bg-[#1a1a1a]">
                            <img v-if="item.media?.length" :src="item.media[0].original_url"
                                class="max-h-full max-w-full object-contain transition-transform duration-500 group-hover:scale-105" />
                            <i v-else class="pi pi-image !text-3xl text-gray-300 dark:text-gray-600" />
                        </div>
                        <div class="p-3">
                            <h3 class="font-medium text-gray-900 dark:text-white text-xs line-clamp-2 m-0 leading-snug">{{ item.name }}</h3>
                            <p class="text-sm font-light tracking-tight mt-1.5 m-0"
                                :style="{ color: 'var(--store-primary)' }">
                                {{ formatCurrency(item.online_price || item.selling_price).replace(/\.00$/, '') }}
                            </p>
                        </div>
                        <!-- Cart quantity badge -->
                        <span v-if="getCartQuantity(item.id) > 0"
                            class="absolute top-2 right-2 min-w-[22px] h-[22px] flex items-center justify-center rounded-full text-[10px] font-bold text-white"
                            :style="{ background: 'var(--store-primary)' }">
                            {{ getCartQuantity(item.id) }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Search (sticky) -->
            <div class="sticky top-[57px] z-30 bg-gray-50/90 dark:bg-black/95 backdrop-blur-md -mx-4 md:-mx-6 px-4 md:px-6 py-3 mb-6">
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
                <!-- Sidebar: Categories + Price filter -->
                <div class="md:w-52 shrink-0 space-y-6">
                    <!-- Category sidebar -->
                    <div v-if="categories.length > 0" class="bg-gray-50/80 dark:bg-[#1c1c1c] rounded-2xl p-4">
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

                    <!-- Price filter -->
                    <div class="bg-gray-50/80 dark:bg-[#1c1c1c] rounded-2xl p-4">
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 m-0 mb-3">Precio</p>
                        <div class="space-y-2">
                            <InputNumber v-model="minPrice" placeholder="Mínimo" mode="currency" currency="MXN"
                                @update:modelValue="onPriceChange" class="w-full"
                                :pt="{ input: { root: { class: '!rounded-xl !text-xs !bg-white dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a] !text-gray-900 dark:!text-white' } } }" />
                            <InputNumber v-model="maxPrice" placeholder="Máximo" mode="currency" currency="MXN"
                                @update:modelValue="onPriceChange" class="w-full"
                                :pt="{ input: { root: { class: '!rounded-xl !text-xs !bg-white dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a] !text-gray-900 dark:!text-white' } } }" />
                        </div>
                    </div>

                    <!-- Clear filters -->
                    <button v-if="hasFilters" @click="clearFilters"
                        class="w-full text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors py-2">
                        Limpiar filtros
                    </button>
                </div>

                <!-- Product grid -->
                <div class="flex-1 min-w-0">
                    <!-- Results count + Sort -->
                    <div class="flex items-center justify-between mb-4">
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 m-0">
                            {{ products.total }} {{ products.total === 1 ? 'producto' : 'productos' }}
                        </p>
                        <Select v-model="sortBy" @change="onSortChange" :options="[
                            { label: 'Más relevantes', value: '' },
                            { label: 'Menor precio', value: 'price_asc' },
                            { label: 'Mayor precio', value: 'price_desc' },
                        ]" optionLabel="label" optionValue="value" placeholder="Ordenar"
                            class="w-40"
                            :pt="{
                                root: { class: '!rounded-xl !text-xs !bg-white dark:!bg-[#232323] !border-gray-100 dark:!border-[#3a3a3a]' }
                            }" />
                    </div>

                    <DataView :value="products.data" layout="grid" :pt="{ content: { class: '!bg-transparent !p-0' } }">
                        <template #grid="slotProps">
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                                <div v-for="item in slotProps.items" :key="item.id"
                                    class="bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] overflow-hidden cursor-pointer hover:border-gray-300 dark:hover:border-gray-600 transition-all duration-300 group relative"
                                    @click="goToProduct(item)">
                                    <!-- Cart quantity badge -->
                                    <span v-if="getCartQuantity(item.id) > 0"
                                        class="absolute top-2 right-2 z-10 min-w-[22px] h-[22px] flex items-center justify-center rounded-full text-[10px] font-bold text-white"
                                        :style="{ background: 'var(--store-primary)' }">
                                        {{ getCartQuantity(item.id) }}
                                    </span>
                                    <!-- Image -->
                                    <div class="h-52 flex items-center justify-center p-6 bg-gray-50 dark:bg-[#1a1a1a] relative">
                                        <img v-if="item.media?.length" :src="item.media[0].original_url"
                                            class="max-h-full max-w-full object-contain transition-transform duration-500 group-hover:scale-105"
                                            :class="{ 'opacity-50': getStockInfo(item).isOut }" />
                                        <i v-else class="pi pi-image !text-4xl text-gray-300 dark:text-gray-600" />
                                        <!-- Out of stock overlay -->
                                        <div v-if="getStockInfo(item).isOut" class="absolute inset-0 flex items-center justify-center">
                                            <span class="text-[10px] uppercase tracking-widest font-bold text-red-500 bg-white/90 dark:bg-black/60 px-3 py-1 rounded-full">
                                                Agotado
                                            </span>
                                        </div>
                                        <!-- Low stock -->
                                        <span v-else-if="getStockInfo(item).available > 0 && getStockInfo(item).available <= 5"
                                            class="absolute bottom-2 left-2 text-[9px] uppercase tracking-widest font-bold text-amber-600 dark:text-amber-400 bg-white/90 dark:bg-black/60 px-2 py-0.5 rounded-full">
                                            {{ getStockInfo(item).available }} quedan
                                        </span>
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
                            :style="{ '--p-paginator-page-button-active-background': 'var(--store-secondary)' }" />
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

        <!-- Back to top -->
        <button v-if="showBackToTop" @click="scrollToTop"
            class="fixed bottom-20 right-6 z-50 w-10 h-10 rounded-full bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] text-gray-500 dark:text-gray-400 flex items-center justify-center shadow-lg hover:shadow-xl hover:-translate-y-0.5 transition-all duration-300"
            title="Volver arriba">
            <i class="pi pi-chevron-up !text-sm" />
        </button>
    </StoreLayout>
</template>
