<script setup>
import { computed, ref, onMounted, onUnmounted } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';

const page = usePage();
const store = computed(() => page.props.store || {});
const isIndexPage = computed(() => page.component === 'Store/Index');

const slug = computed(() => {
    const parts = window.location.pathname.split('/');
    return parts[2] || '';
});

// Reactive cart count
const cartVersion = ref(0);

const cartCount = computed(() => {
    // eslint-disable-next-line no-unused-vars
    void cartVersion.value; // reactivity trigger
    try {
        const items = JSON.parse(sessionStorage.getItem('store_cart') || '[]');
        return items.reduce((sum, i) => sum + i.quantity, 0);
    } catch {
        return 0;
    }
});

const onCartUpdated = () => { cartVersion.value++; };

onMounted(() => window.addEventListener('cart-updated', onCartUpdated));
onUnmounted(() => window.removeEventListener('cart-updated', onCartUpdated));

const rootStyles = computed(() => ({
    '--store-primary': store.value.primary_color || '#3B82F6',
    '--store-primary-light': store.value.primary_color
        ? store.value.primary_color + '1A'
        : 'rgba(59,130,246,0.1)',
    '--store-primary-glow': store.value.primary_color
        ? store.value.primary_color + '26'
        : 'rgba(59,130,246,0.15)',
    '--store-secondary': store.value.secondary_color || '#1D4ED8',
}));

const isDarkTheme = computed(() => store.value.theme_mode === 'dark');

const banners = computed(() => store.value.banners || []);

const mpEnabled = computed(() => store.value.payment_mp_enabled ?? false);
const mpTestMode = computed(() => store.value.mp_test_mode ?? false);

const galleriaPt = computed(() => ({
    root: { class: '!w-full !border-0 !rounded-none' },
    content: { class: '!border-0' },
    itemWrapper: { class: '!w-full !border-0' },
    item: { class: '!w-full !flex !justify-center !border-0' },
    previousItemButton: {
        class: '!w-10 !h-10 !rounded-full !bg-white/20 dark:!bg-black/30 !backdrop-blur-md !text-white !border-0 hover:!bg-white/40 dark:hover:!bg-black/50 !transition-all !absolute !left-4 !top-1/2 !-translate-y-1/2 !z-10',
    },
    nextItemButton: {
        class: '!w-10 !h-10 !rounded-full !bg-white/20 dark:!bg-black/30 !backdrop-blur-md !text-white !border-0 hover:!bg-white/40 dark:hover:!bg-black/50 !transition-all !absolute !right-4 !top-1/2 !-translate-y-1/2 !z-10',
    },
}));
</script>

<template>
    <div :style="rootStyles"
        :class="[
            'min-h-screen flex flex-col font-sans antialiased',
            isDarkTheme ? 'bg-[#1c1c1c] text-gray-200' : 'bg-[#faf8f5] text-gray-800'
        ]">
        <!-- Toast -->
        <Toast position="bottom-right" group="store" :pt="{ root: { class: '!z-[9999]' }, message: { class: '!rounded-2xl !text-sm' } }" />

        <!-- Header — Midori clean style -->
        <header :class="[
            'sticky top-0 z-40 backdrop-blur-xl border-b transition-colors',
            isDarkTheme ? 'bg-[#1c1c1c]/95 border-[#2a2a2a]' : 'bg-[#faf8f5]/90 border-[#e8e3dc]'
        ]">
            <div class="max-w-7xl mx-auto px-4 md:px-8 py-4 flex items-center justify-between">
                <!-- Logo + Name -->
                <Link :href="route('store.home', { slug: slug })" class="flex items-center gap-3.5 group">
                    <img v-if="store.logo_url" :src="store.logo_url"
                        class="h-10 max-w-[100px] object-contain object-left" alt="Logo" />
                    <div v-else class="h-9 w-9 rounded-lg flex items-center justify-center text-white font-bold text-xs shrink-0"
                        :style="{ background: 'var(--store-primary)' }">
                        {{ (store.name || 'T').charAt(0).toUpperCase() }}
                    </div>
                    <div class="flex flex-col">
                        <span class="text-[13px] font-medium tracking-wide m-0 leading-tight"
                            :class="isDarkTheme ? 'text-gray-100' : 'text-gray-800'">
                            {{ store.name || 'Tienda' }}
                        </span>
                        <span v-if="store.tagline" class="text-[10px] tracking-[0.15em] font-medium m-0 leading-tight"
                            :class="isDarkTheme ? 'text-gray-500' : 'text-gray-400'">
                            {{ store.tagline }}
                        </span>
                    </div>
                </Link>

                <!-- Cart -->
                <Link :href="route('store.cart', { slug: slug })"
                    :class="[
                        'relative w-10 h-10 flex items-center justify-center rounded-full border transition-all duration-200',
                        isDarkTheme
                            ? 'border-[#2a2a2a] bg-[#252525] hover:border-gray-600 text-gray-400 hover:text-gray-200'
                            : 'border-[#e8e3dc] bg-white hover:border-gray-300 text-gray-500 hover:text-gray-700'
                    ]">
                    <i class="pi pi-shopping-bag !text-sm" />
                    <span v-if="cartCount > 0"
                        class="absolute -top-1 -right-1 min-w-[18px] h-[18px] flex items-center justify-center rounded-full text-[10px] font-bold text-white"
                        :style="{ background: 'var(--store-primary)' }">
                        {{ cartCount }}
                    </span>
                </Link>
            </div>
        </header>

        <!-- Banner carousel -->
        <div v-if="isIndexPage && banners.length > 0" class="w-full">
            <Galleria :value="banners" :numVisible="1" :showThumbnails="false" :showIndicators="false"
                :showItemNavigators="true" :showItemNavigatorsOnHover="true"
                :autoPlay="true" :circular="true" :transitionInterval="5000"
                :pt="galleriaPt">
                <template #item="slotProps">
                    <img :src="slotProps.item.url"
                        class="w-full h-52 md:h-72 lg:h-[420px] object-cover" alt="Banner" />
                </template>
            </Galleria>
        </div>

        <!-- Main Content -->
        <main class="flex-1">
            <slot />
        </main>

        <!-- WhatsApp floating button -->
        <a v-if="store.whatsapp_number"
            :href="'https://wa.me/' + store.whatsapp_number.replace(/\D/g, '')"
            target="_blank"
            class="fixed bottom-6 right-6 z-50 w-12 h-12 rounded-full bg-green-500 hover:bg-green-600 text-white flex items-center justify-center shadow-lg hover:shadow-xl transition-all duration-300 hover:-translate-y-0.5"
            title="Contactar por WhatsApp">
            <i class="pi pi-whatsapp !text-xl" />
        </a>

        <!-- Footer — Midori style -->
        <footer :class="[
            'border-t mt-auto',
            isDarkTheme ? 'border-[#2a2a2a] bg-[#1a1a1a]' : 'border-[#e8e3dc] bg-white'
        ]">
            <div class="max-w-7xl mx-auto px-4 md:px-8 py-8">
                <div class="flex flex-col md:flex-row items-center justify-between gap-6">
                    <!-- Store info -->
                    <div class="text-center md:text-left">
                        <p v-if="store.name" class="text-sm font-medium m-0 mb-1"
                            :class="isDarkTheme ? 'text-gray-300' : 'text-gray-700'">
                            {{ store.name }}
                        </p>
                        <p v-if="store.description" class="text-[11px] m-0 leading-relaxed max-w-md"
                            :class="isDarkTheme ? 'text-gray-500' : 'text-gray-400'">
                            {{ store.description }}
                        </p>
                    </div>

                    <!-- Mercado Pago trust badge -->
                    <div class="flex items-center gap-3">
                        <span class="text-[10px]"
                            :class="isDarkTheme ? 'text-gray-500' : 'text-gray-400'">
                            Pagos seguros con
                        </span>
                        <img src="/images/Mercado_Pago_logo.webp" alt="Mercado Pago"
                            class="h-10 object-contain opacity-80" />
                        <span v-if="mpTestMode" class="text-[9px] px-2 py-0.5 rounded-full font-medium bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400">
                            Modo prueba
                        </span>
                    </div>

                    <!-- Links -->
                    <div class="flex items-center gap-6">
                        <Link v-if="store.terms_policy" :href="route('store.policies', { slug: slug })"
                            :class="[
                                'text-[11px] transition-colors hover:underline underline-offset-4',
                                isDarkTheme ? 'text-gray-500 hover:text-gray-300' : 'text-gray-400 hover:text-gray-600'
                            ]">
                            Políticas de la tienda
                        </Link>
                        <span class="text-[11px]"
                            :class="isDarkTheme ? 'text-gray-600' : 'text-gray-300'">
                            Desarrollado por EzyVentas
                        </span>
                    </div>
                </div>
                <p v-if="store.footer_note" class="text-[10px] text-center mt-4 m-0"
                    :class="isDarkTheme ? 'text-gray-600' : 'text-gray-400'">
                    {{ store.footer_note }}
                </p>
            </div>
        </footer>
    </div>
</template>
