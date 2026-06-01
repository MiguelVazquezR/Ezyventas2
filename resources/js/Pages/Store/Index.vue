<script setup>
import { ref, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import StoreLayout from '@/Layouts/StoreLayout.vue';
import { useDebounceFn } from '@vueuse/core';

const page = usePage();
const store = computed(() => page.props.store || {});
const isDarkTheme = computed(() => store.value.theme_mode === 'dark');

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
const onScroll = () => { showBackToTop.value = window.scrollY > 500; };
window.addEventListener('scroll', onScroll, { passive: true });
const scrollToTop = () => { window.scrollTo({ top: 0, behavior: 'smooth' }); };

const pageTitle = computed(() => store.value.name || 'Tienda');

const getProductPrice = (item) => Number(item.online_price || item.selling_price);
const getStockInfo = (item) => {
    const available = Number(item.available_stock ?? 0);
    return { available, isOut: available <= 0 };
};
</script>

<template>
    <Head :title="pageTitle" />
    <StoreLayout>
        <div class="max-w-7xl mx-auto px-4 md:px-8 py-8 md:py-12">
            <!-- Welcome message -->
            <div v-if="store.welcome_message" class="mb-12 text-center">
                <p class="text-lg md:text-xl font-light tracking-wide leading-relaxed max-w-2xl mx-auto m-0"
                    :class="isDarkTheme ? 'text-gray-300' : 'text-gray-600'">
                    {{ store.welcome_message }}
                </p>
            </div>

            <!-- Search bar -->
            <div class="max-w-xl mx-auto mb-10">
                <IconField iconPosition="left" class="w-full">
                    <InputIcon class="pi pi-search !text-sm"
                        :class="isDarkTheme ? 'text-gray-600' : 'text-gray-400'" />
                    <InputText v-model="search" @input="onSearch" placeholder="Buscar productos..."
                        class="w-full"
                        :pt="{
                            root: {
                                class: [
                                    '!rounded-full !py-3 !pl-10 !pr-5 !text-sm !border transition-colors',
                                    isDarkTheme
                                        ? '!bg-[#252525] !border-[#2a2a2a] !text-gray-200 placeholder:!text-gray-600 focus:!border-gray-500'
                                        : '!bg-white !border-[#e8e3dc] !text-gray-800 placeholder:!text-gray-400 focus:!border-gray-400'
                                ].join(' ')
                            }
                        }" />
                </IconField>
            </div>

            <!-- Category chips -->
            <div v-if="categories.length > 0" class="flex flex-wrap justify-center gap-2 mb-10">
                <button v-for="cat in categories" :key="cat.id"
                    class="px-5 py-2 text-xs font-medium rounded-full transition-all duration-200 border"
                    :class="[
                        activeCategory === String(cat.id)
                            ? 'text-white border-transparent'
                            : isDarkTheme
                                ? 'text-gray-400 bg-[#252525] border-[#2a2a2a] hover:border-gray-600 hover:text-gray-200'
                                : 'text-gray-500 bg-white border-[#e8e3dc] hover:border-gray-300 hover:text-gray-700'
                    ]"
                    :style="activeCategory === String(cat.id) ? { background: 'var(--store-primary)', borderColor: 'var(--store-primary)' } : {}"
                    @click="filterCategory(String(cat.id))">
                    {{ cat.name }}
                </button>
            </div>

            <!-- Sort + count bar -->
            <div v-if="products.data.length > 0" class="flex items-center justify-between mb-6">
                <p class="text-[11px] tracking-wider font-medium m-0"
                    :class="isDarkTheme ? 'text-gray-500' : 'text-gray-400'">
                    {{ products.total }} {{ products.total === 1 ? 'producto' : 'productos' }}
                </p>
                <Select v-model="sortBy" @change="onSortChange" :options="[
                    { label: 'Más relevantes', value: '' },
                    { label: 'Menor precio', value: 'price_asc' },
                    { label: 'Mayor precio', value: 'price_desc' },
                ]" optionLabel="label" optionValue="value" placeholder="Ordenar"
                    class="w-44"
                    :pt="{
                        root: {
                            class: [
                                '!rounded-full !text-xs',
                                isDarkTheme ? '!bg-[#252525] !border-[#2a2a2a] !text-gray-300' : '!bg-white !border-[#e8e3dc] !text-gray-600'
                            ].join(' ')
                        }
                    }" />
            </div>

            <!-- Clear filters -->
            <div v-if="hasFilters" class="flex justify-center mb-8">
                <button @click="clearFilters"
                    class="text-[10px] uppercase tracking-widest font-bold transition-colors py-1 px-4 rounded-full border"
                    :class="isDarkTheme ? 'text-gray-500 border-[#2a2a2a] hover:text-gray-300 hover:border-gray-600' : 'text-gray-400 border-[#e8e3dc] hover:text-gray-600 hover:border-gray-300'">
                    Limpiar filtros
                </button>
            </div>

            <!-- Product grid -->
            <DataView :value="products.data" layout="grid" :pt="{ content: { class: '!bg-transparent !p-0' } }">
                <template #grid="slotProps">
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-5 md:gap-6">
                        <div v-for="item in slotProps.items" :key="item.id"
                            :class="[
                                'group cursor-pointer rounded-2xl overflow-hidden transition-all duration-300 border',
                                isDarkTheme
                                    ? 'bg-[#252525] border-transparent hover:border-[#3a3a3a]'
                                    : 'bg-white border-transparent hover:border-[#e0dbd4] hover:shadow-sm'
                            ]"
                            @click="goToProduct(item)">
                            <!-- Image -->
                            <div :class="[
                                'aspect-square flex items-center justify-center p-6 relative overflow-hidden',
                                isDarkTheme ? 'bg-[#1a1a1a]' : 'bg-[#f5f2ed]'
                            ]">
                                <img v-if="item.media?.length" :src="item.media[0].original_url"
                                    class="max-h-full max-w-full object-contain transition-transform duration-500 group-hover:scale-[1.04]"
                                    :class="{ 'opacity-40': getStockInfo(item).isOut }" />
                                <i v-else class="pi pi-image !text-3xl"
                                    :class="isDarkTheme ? 'text-gray-700' : 'text-gray-300'" />
                                <!-- Stock overlay -->
                                <div v-if="getStockInfo(item).isOut"
                                    class="absolute inset-0 flex items-center justify-center"
                                    :class="isDarkTheme ? 'bg-black/30' : 'bg-black/5'">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-red-500 px-3 py-1 rounded-full"
                                        :class="isDarkTheme ? 'bg-black/60' : 'bg-white/90'">
                                        Agotado
                                    </span>
                                </div>
                                <!-- Cart badge -->
                                <span v-if="getCartQuantity(item.id) > 0"
                                    class="absolute top-3 right-3 min-w-[22px] h-[22px] flex items-center justify-center rounded-full text-[10px] font-bold text-white shadow-sm"
                                    :style="{ background: 'var(--store-primary)' }">
                                    {{ getCartQuantity(item.id) }}
                                </span>
                            </div>
                            <!-- Info -->
                            <div class="p-4">
                                <p class="text-[10px] uppercase tracking-widest font-bold m-0 mb-1"
                                    :class="isDarkTheme ? 'text-gray-600' : 'text-gray-400'">
                                    {{ item.category?.name || '' }}
                                </p>
                                <h3 class="text-[13px] font-medium leading-snug m-0 line-clamp-2"
                                    :class="isDarkTheme ? 'text-gray-200' : 'text-gray-800'">
                                    {{ item.name }}
                                </h3>
                                <p class="text-sm font-medium tracking-tight mt-2 m-0"
                                    :style="{ color: 'var(--store-primary)' }">
                                    {{ formatCurrency(getProductPrice(item)).replace(/\.00$/, '') }}
                                </p>
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
                                ? '!rounded-full !text-white'
                                : [
                                    '!rounded-full !text-gray-500',
                                    isDarkTheme
                                        ? '!bg-[#252525] !border-[#2a2a2a] hover:!bg-[#2a2a2a]'
                                        : '!bg-white !border-[#e8e3dc] hover:!bg-gray-50'
                                ].join(' ')
                        }),
                    }"
                    :style="{ '--p-paginator-page-button-active-background': 'var(--store-primary)' }" />
            </div>

            <!-- Empty state -->
            <div v-if="products.data.length === 0" class="text-center py-20">
                <div :class="[
                    'w-20 h-20 mx-auto mb-6 rounded-full flex items-center justify-center',
                    isDarkTheme ? 'bg-[#252525]' : 'bg-[#f5f2ed]'
                ]">
                    <i class="pi pi-search !text-2xl"
                        :class="isDarkTheme ? 'text-gray-600' : 'text-gray-300'" />
                </div>
                <p class="text-sm m-0 mb-1"
                    :class="isDarkTheme ? 'text-gray-400' : 'text-gray-500'">No se encontraron productos.</p>
                <p class="text-xs m-0"
                    :class="isDarkTheme ? 'text-gray-600' : 'text-gray-400'">Intenta con otra búsqueda o categoría.</p>
            </div>
        </div>

        <!-- Back to top -->
        <button v-if="showBackToTop" @click="scrollToTop"
            :class="[
                'fixed bottom-20 right-6 z-50 w-10 h-10 rounded-full border flex items-center justify-center shadow-sm hover:shadow transition-all duration-300',
                isDarkTheme ? 'bg-[#252525] border-[#2a2a2a] text-gray-400' : 'bg-white border-[#e8e3dc] text-gray-500'
            ]"
            title="Volver arriba">
            <i class="pi pi-chevron-up !text-sm" />
        </button>
    </StoreLayout>
</template>
