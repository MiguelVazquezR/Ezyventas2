<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { useToast } from 'primevue/usetoast';
import StoreLayout from '@/Layouts/StoreLayout.vue';
import ProductCard from '@/Components/Store/ProductCard.vue';
import StoreFilters from '@/Components/Store/StoreFilters.vue';

const page = usePage();
const store = computed(() => page.props.store || {});
const isDarkTheme = computed(() => store.value.theme_mode === 'dark');
const allowOutOfStock = computed(() => store.value.allow_out_of_stock_purchases ?? false);

const toast = useToast();

const props = defineProps({
    products: Object,
    featured: Array,
    categories: Array,
    filters: Object,
    priceRange: { type: Object, default: () => ({ min: 0, max: 0 }) },
    catalogTotal: { type: Number, default: 0 },
});

const search = ref(props.filters?.search || '');
const activeCategory = ref(props.filters?.category || '');
const minPrice = ref(props.filters?.min_price || '');
const maxPrice = ref(props.filters?.max_price || '');
const sortBy = ref(props.filters?.sort || '');
const filtersOpen = ref(false);

const sortOptions = [
    { label: 'Más relevantes', value: '' },
    { label: 'Menor precio', value: 'price_asc' },
    { label: 'Mayor precio', value: 'price_desc' },
];

const formatCurrency = (num) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' })
        .format(num || 0)
        .replace(/\.00$/, '');
};

/* Cart helpers */
const readCart = () => {
    try {
        return JSON.parse(sessionStorage.getItem('store_cart') || '[]');
    } catch {
        return [];
    }
};

const cartItems = ref(readCart());

const cartQuantities = computed(() => {
    return cartItems.value.reduce((map, item) => {
        map[item.product_id] = item.quantity;
        return map;
    }, {});
});

const getCartQuantity = (productId) => cartQuantities.value[productId] || 0;

const syncCart = () => { cartItems.value = readCart(); };

const addToCart = (product) => {
    if (getStockInfo(product).isOut && !allowOutOfStock.value) return;

    const items = readCart();
    const existing = items.find(i => i.product_id === product.id);
    const step = product.is_bulk ? 0.1 : 1;

    if (existing) {
        existing.quantity += step;
    } else {
        items.push({
            product_id: product.id,
            name: product.name,
            price: getProductPrice(product),
            quantity: step,
            image_url: product.media?.length ? product.media[0].original_url : null,
            is_bulk: product.is_bulk || false,
            measure_unit: product.measure_unit || '',
        });
    }

    sessionStorage.setItem('store_cart', JSON.stringify(items));
    syncCart();
    window.dispatchEvent(new Event('cart-updated'));

    toast.add({
        severity: 'success',
        summary: 'Agregado al carrito',
        detail: product.name,
        life: 2000,
        group: 'store',
    });
};

/* Navigation & filters */
const buildQuery = (extra = {}) => ({
    search: search.value || undefined,
    category: activeCategory.value || undefined,
    min_price: minPrice.value || undefined,
    max_price: maxPrice.value || undefined,
    sort: sortBy.value || undefined,
    ...extra,
});

const visit = (query) => {
    router.get(window.location.pathname, query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const applyFilters = useDebounceFn(() => visit(buildQuery()), 350);

const onSearch = () => applyFilters();
const onPriceApply = () => applyFilters();

const onSortChange = () => visit(buildQuery());

const selectCategory = (categoryId) => {
    activeCategory.value = categoryId || '';
    visit(buildQuery());
};

const clearFilters = () => {
    search.value = '';
    activeCategory.value = '';
    minPrice.value = '';
    maxPrice.value = '';
    sortBy.value = '';
    visit({});
    filtersOpen.value = false;
};

const hasFilters = computed(() => Boolean(
    search.value || activeCategory.value || minPrice.value || maxPrice.value || sortBy.value
));

const activeFilterCount = computed(() => {
    let count = 0;
    if (activeCategory.value) count++;
    if (minPrice.value || maxPrice.value) count++;
    return count;
});

const activeCategoryName = computed(() => {
    const match = (props.categories || []).find(c => String(c.id) === activeCategory.value);
    return match?.name || '';
});

const priceChipLabel = computed(() => {
    const min = Number(minPrice.value) || 0;
    const max = Number(maxPrice.value) || 0;
    if (min && max) return `${formatCurrency(min)} - ${formatCurrency(max)}`;
    if (min) return `Desde ${formatCurrency(min)}`;
    if (max) return `Hasta ${formatCurrency(max)}`;
    return '';
});

const onPageChange = (event) => {
    visit(buildQuery({ page: event.page + 1 }));
};

const goToProduct = (product) => {
    const slug = window.location.pathname.split('/')[2];
    router.get(route('store.product.show', { slug, product: product.id }));
};

/* Scroll helpers */
const showBackToTop = ref(false);
const onScroll = () => { showBackToTop.value = window.scrollY > 500; };
const scrollToTop = () => { window.scrollTo({ top: 0, behavior: 'smooth' }); };

onMounted(() => {
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('cart-updated', syncCart);
});

onUnmounted(() => {
    window.removeEventListener('scroll', onScroll);
    window.removeEventListener('cart-updated', syncCart);
});

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
        <div class="mx-auto w-full max-w-[1720px] px-4 py-6 md:px-6 lg:px-8 lg:py-9">
            <!-- Store intro -->
            <section class="mb-6 flex flex-col gap-5 lg:mb-8 lg:flex-row lg:items-end lg:justify-between">
                <div class="min-w-0">
                    <p class="m-0 mb-1.5 text-[10px] font-bold uppercase tracking-[0.22em]"
                        :class="isDarkTheme ? 'text-gray-600' : 'text-gray-400'">
                        Catálogo en línea
                    </p>
                    <h1 class="m-0 max-w-2xl text-xl font-light leading-snug tracking-tight md:text-2xl"
                        :class="isDarkTheme ? 'text-gray-100' : 'text-gray-900'">
                        {{ store.welcome_message || 'Explora nuestro catálogo de productos' }}
                    </h1>
                </div>

                <div class="w-full lg:w-[400px] xl:w-[460px]">
                    <IconField iconPosition="left" class="w-full">
                        <InputIcon class="pi pi-search !text-sm"
                            :class="isDarkTheme ? 'text-gray-600' : 'text-gray-400'" />
                        <InputText v-model="search" @input="onSearch" placeholder="Buscar productos..."
                            class="w-full" aria-label="Buscar productos"
                            :pt="{
                                root: {
                                    class: [
                                        '!w-full !rounded-full !py-3 !pl-10 !pr-5 !text-sm !border !shadow-none transition-colors',
                                        isDarkTheme
                                            ? '!bg-[#232323] !border-[#2f2f2f] !text-gray-200 placeholder:!text-gray-600 focus:!border-gray-500'
                                            : '!bg-white !border-[#efeae2] !text-gray-800 placeholder:!text-gray-400 focus:!border-gray-400'
                                    ].join(' ')
                                }
                            }" />
                    </IconField>
                </div>
            </section>

            <div class="flex items-start gap-6 xl:gap-8">
                <!-- Desktop filters -->
                <aside class="hidden w-56 shrink-0 lg:block xl:w-64">
                    <div :class="[
                        'sticky top-24 rounded-3xl border p-5',
                        isDarkTheme ? 'border-[#2c2c2c] bg-[#232323]' : 'border-[#efeae2] bg-white'
                    ]">
                        <StoreFilters :categories="categories" :price-range="priceRange"
                            :active-category="activeCategory" :min-price="minPrice" :max-price="maxPrice"
                            :total-products="catalogTotal" :dark="isDarkTheme"
                            @select-category="selectCategory" @update:min-price="minPrice = $event"
                            @update:max-price="maxPrice = $event" @apply="onPriceApply" @clear="clearFilters" />
                    </div>
                </aside>

                <div class="min-w-0 flex-1">
                    <!-- Toolbar -->
                    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-2.5">
                            <Button type="button" outlined class="lg:!hidden !rounded-full !px-3.5 !py-2"
                                :pt="{
                                    root: {
                                        class: [
                                            '!text-[11px] !font-semibold !shadow-none',
                                            isDarkTheme
                                                ? '!bg-[#232323] !border-[#2f2f2f] !text-gray-300'
                                                : '!bg-white !border-[#efeae2] !text-gray-600'
                                        ].join(' ')
                                    }
                                }" @click="filtersOpen = true">
                                <i class="pi pi-sliders-h !text-xs mr-2" />
                                Filtros
                                <span v-if="activeFilterCount > 0"
                                    class="ml-1.5 flex h-4 min-w-[16px] items-center justify-center rounded-full px-1 text-[9px] font-bold text-white"
                                    :style="{ background: 'var(--store-primary)' }">
                                    {{ activeFilterCount }}
                                </span>
                            </Button>

                            <p class="m-0 text-[11px] font-medium tracking-wide"
                                :class="isDarkTheme ? 'text-gray-500' : 'text-gray-400'">
                                {{ products.total }} {{ products.total === 1 ? 'producto' : 'productos' }}
                            </p>
                        </div>

                        <div class="flex items-center gap-2">
                            <Select v-model="sortBy" @change="onSortChange" :options="sortOptions"
                                optionLabel="label" optionValue="value" placeholder="Ordenar"
                                class="w-40 md:w-44" aria-label="Ordenar productos"
                                :pt="{
                                    root: {
                                        class: [
                                            '!rounded-full !text-xs !border !shadow-none',
                                            isDarkTheme
                                                ? '!bg-[#232323] !border-[#2f2f2f] !text-gray-300'
                                                : '!bg-white !border-[#efeae2] !text-gray-600'
                                        ].join(' ')
                                    }
                                }" />
                        </div>
                    </div>

                    <!-- Active filters -->
                    <div v-if="activeCategoryName || priceChipLabel" class="mb-4 flex flex-wrap items-center gap-2">
                        <button v-if="activeCategoryName" type="button"
                            class="group flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[10px] font-semibold uppercase tracking-widest transition-colors"
                            :class="isDarkTheme
                                ? 'border-[#2f2f2f] bg-[#232323] text-gray-400 hover:border-gray-600 hover:text-gray-200'
                                : 'border-[#efeae2] bg-white text-gray-500 hover:border-[#ddd5c9] hover:text-gray-700'"
                            @click="selectCategory('')">
                            {{ activeCategoryName }}
                            <i class="pi pi-times !text-[8px]" />
                        </button>

                        <button v-if="priceChipLabel" type="button"
                            class="group flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[10px] font-semibold uppercase tracking-widest transition-colors"
                            :class="isDarkTheme
                                ? 'border-[#2f2f2f] bg-[#232323] text-gray-400 hover:border-gray-600 hover:text-gray-200'
                                : 'border-[#efeae2] bg-white text-gray-500 hover:border-[#ddd5c9] hover:text-gray-700'"
                            @click="minPrice = ''; maxPrice = ''; onPriceApply()">
                            {{ priceChipLabel }}
                            <i class="pi pi-times !text-[8px]" />
                        </button>
                    </div>

                    <!-- Product grid -->
                    <div v-if="products.data.length > 0"
                        class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-3 xl:grid-cols-4 xl:gap-5 3xl:grid-cols-5 2xl:grid-cols-6">
                        <ProductCard v-for="item in products.data" :key="item.id" :product="item"
                            :dark="isDarkTheme" :allow-out-of-stock="allowOutOfStock"
                            :cart-quantity="getCartQuantity(item.id)" @open="goToProduct(item)"
                            @add="addToCart(item)" />
                    </div>

                    <!-- Pagination -->
                    <div v-if="products.total > products.per_page" class="mt-9 flex justify-center">
                        <Paginator :rows="products.per_page" :totalRecords="products.total"
                            :first="(products.current_page - 1) * products.per_page" @page="onPageChange"
                            :pt="{
                                root: { class: '!bg-transparent !p-0 !gap-1' },
                                pageButton: ({ context }) => ({
                                    class: context.active
                                        ? '!rounded-full !border-0 !text-white !shadow-none'
                                        : [
                                            '!rounded-full !border !shadow-none !text-gray-500',
                                            isDarkTheme
                                                ? '!bg-[#232323] !border-[#2f2f2f] hover:!bg-[#2b2b2b]'
                                                : '!bg-white !border-[#efeae2] hover:!bg-[#f7f4ef]'
                                        ].join(' ')
                                }),
                            }"
                            :style="{ '--p-paginator-page-button-active-background': 'var(--store-primary)' }" />
                    </div>

                    <!-- Empty state -->
                    <div v-if="products.data.length === 0" class="flex flex-col items-center py-24 text-center">
                        <div :class="[
                            'mb-5 flex h-20 w-20 items-center justify-center rounded-full',
                            isDarkTheme ? 'bg-[#232323]' : 'bg-white ring-1 ring-[#efeae2]'
                        ]">
                            <i class="pi pi-search !text-2xl" :class="isDarkTheme ? 'text-gray-600' : 'text-gray-300'" />
                        </div>
                        <p class="m-0 mb-1 text-sm" :class="isDarkTheme ? 'text-gray-400' : 'text-gray-500'">
                            No se encontraron productos.
                        </p>
                        <p class="m-0 text-xs" :class="isDarkTheme ? 'text-gray-600' : 'text-gray-400'">
                            Intenta con otra búsqueda o ajusta los filtros.
                        </p>
                        <Button v-if="hasFilters" type="button" text class="mt-4 !rounded-full !text-xs"
                            :pt="{ root: { class: '!text-[var(--store-primary)]' } }" @click="clearFilters">
                            Limpiar filtros
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mobile filters drawer -->
        <Drawer v-model:visible="filtersOpen" position="left" class="!w-[86vw] !max-w-xs"
            :pt="{
                root: { class: '!rounded-r-3xl' },
                header: { class: isDarkTheme ? '!bg-[#232323] !border-[#2c2c2c]' : '!bg-white !border-[#efeae2]' },
                content: { class: isDarkTheme ? '!bg-[#232323] !px-5 !py-4' : '!bg-white !px-5 !py-4' },
            }">
            <template #header>
                <span class="text-[10px] font-bold uppercase tracking-[0.2em]"
                    :class="isDarkTheme ? 'text-gray-400' : 'text-gray-500'">
                    Filtros
                </span>
            </template>

            <StoreFilters :categories="categories" :price-range="priceRange" :active-category="activeCategory"
                :min-price="minPrice" :max-price="maxPrice" :total-products="catalogTotal" :dark="isDarkTheme"
                @select-category="selectCategory" @update:min-price="minPrice = $event"
                @update:max-price="maxPrice = $event" @apply="onPriceApply" @clear="clearFilters" />
        </Drawer>

        <!-- Back to top -->
        <button v-if="showBackToTop" type="button" @click="scrollToTop" :class="[
            'fixed bottom-20 right-6 z-50 flex h-10 w-10 items-center justify-center rounded-full border shadow-sm transition-all duration-300 hover:shadow',
            isDarkTheme ? 'border-[#2f2f2f] bg-[#232323] text-gray-400' : 'border-[#efeae2] bg-white text-gray-500'
        ]" title="Volver arriba">
            <i class="pi pi-chevron-up !text-sm" />
        </button>
    </StoreLayout>
</template>
