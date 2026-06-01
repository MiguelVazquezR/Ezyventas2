<script setup>
import { computed } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';

const page = usePage();
const store = computed(() => page.props.store || {});
const isIndexPage = computed(() => page.component === 'Store/Index');

const slug = computed(() => {
    const parts = window.location.pathname.split('/');
    return parts[2] || '';
});

const cartCount = computed(() => {
    try {
        const items = JSON.parse(sessionStorage.getItem('store_cart') || '[]');
        return items.reduce((sum, i) => sum + i.quantity, 0);
    } catch {
        return 0;
    }
});

const rootStyles = computed(() => ({
    '--store-primary': store.value.primary_color || '#3B82F6',
    '--store-primary-glow': store.value.primary_color
        ? store.value.primary_color + '33'
        : 'rgba(59,130,246,0.2)',
    '--store-secondary': store.value.secondary_color || '#1D4ED8',
}));

const isDarkTheme = computed(() => store.value.theme_mode === 'dark');

const banners = computed(() => store.value.banners || []);
</script>

<template>
    <div :style="rootStyles" :class="['min-h-screen flex flex-col', isDarkTheme ? 'bg-gray-200' : 'bg-gray-50']">
        <!-- Header -->
        <header class="sticky top-0 z-40 bg-white/80 backdrop-blur-xl border-b"
            :class="[isDarkTheme ? '!bg-black/95 border-gray-800' : 'border-gray-100']">
            <div class="max-w-7xl mx-auto px-4 md:px-6 py-3 flex items-center justify-between">
                <Link :href="route('store.home', { slug: slug })" class="flex items-center gap-3 group">
                    <img v-if="store.logo_url" :src="store.logo_url" class="h-12 max-w-[120px] object-contain object-left" alt="Logo" />
                    <div v-else class="h-10 w-10 rounded-xl flex items-center justify-center text-white font-bold text-sm shrink-0" :style="{ background: 'var(--store-primary)' }">
                        {{ (store.name || 'T').charAt(0).toUpperCase() }}
                    </div>
                    <div>
                        <h1 class="text-base font-semibold m-0 transition-colors"
                            :class="isDarkTheme ? 'text-white' : 'text-gray-900'"
                            :style="{ color: 'var(--store-primary)' }">
                            {{ store.name || 'Tienda' }}
                        </h1>
                        <p v-if="store.tagline" class="text-[10px] uppercase tracking-widest font-bold m-0" :style="{ color: 'var(--store-secondary)' }">
                            {{ store.tagline }}
                        </p>
                    </div>
                </Link>
                <!-- Cart icon -->
                <Link :href="route('store.cart', { slug: slug })"
                    :class="[
                        'relative w-10 h-10 flex items-center justify-center rounded-full border transition-colors',
                        isDarkTheme
                            ? 'border-gray-700 bg-[#1a1a1a] hover:border-gray-600'
                            : 'border-gray-100 bg-white hover:border-gray-300'
                    ]">
                    <i class="pi pi-shopping-cart !text-sm"
                        :class="isDarkTheme ? 'text-gray-300' : 'text-gray-600'" />
                    <span v-if="cartCount > 0"
                        class="absolute -top-1.5 -right-1.5 min-w-[18px] h-[18px] flex items-center justify-center rounded-full text-[10px] font-bold text-white"
                        :style="{ background: 'var(--store-primary)' }">
                        {{ cartCount }}
                    </span>
                </Link>
            </div>
        </header>

        <!-- Banner carousel (only on store home) -->
        <div v-if="isIndexPage && banners.length > 0" class="w-full">
            <Galleria :value="banners" :numVisible="1" :showThumbnails="false" :showIndicators="banners.length > 1"
                :autoPlay="true" :circular="true" :transitionInterval="4000"
                :pt="{
                    root: { class: '!w-full' },
                    itemWrapper: { class: '!w-full' },
                    item: { class: '!w-full !flex !justify-center' },
                }">
                <template #item="slotProps">
                    <img :src="slotProps.item.url" class="w-full h-48 md:h-64 lg:h-80 object-cover" alt="Banner" />
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
            class="fixed bottom-6 right-6 z-50 w-14 h-14 rounded-full bg-green-500 hover:bg-green-600 text-white flex items-center justify-center shadow-lg hover:shadow-xl transition-all duration-300 hover:scale-110"
            title="Contactar por WhatsApp">
            <i class="pi pi-whatsapp !text-2xl" />
        </a>

        <!-- Footer -->
        <footer :class="[
            'border-t mt-auto',
            isDarkTheme ? 'border-gray-800 bg-black' : 'border-gray-100 bg-white'
        ]">
            <div class="max-w-7xl mx-auto px-4 md:px-6 py-6 text-center">
                <p v-if="store.description" class="text-[11px] m-0 leading-relaxed mb-2"
                    :class="isDarkTheme ? 'text-gray-400' : 'text-gray-500'">{{ store.description }}</p>
                <div class="flex items-center justify-center gap-4 flex-wrap mb-2">
                    <Link v-if="store.terms_policy" :href="route('store.policies', { slug: slug })"
                        :class="[
                            'text-[11px] transition-colors',
                            isDarkTheme ? 'text-gray-500 hover:text-gray-300' : 'text-gray-400 hover:text-gray-600'
                        ]">
                        Políticas de la tienda
                    </Link>
                </div>
                <p v-if="store.footer_note" class="text-[11px] m-0 leading-relaxed"
                    :class="isDarkTheme ? 'text-gray-400' : 'text-gray-500'">{{ store.footer_note }}</p>
                <p v-if="!store.footer_note && !store.description" class="text-[11px] m-0"
                    :class="isDarkTheme ? 'text-gray-500' : 'text-gray-400'">
                    {{ store.name || 'Tienda' }} &mdash; Desarrollado por <span class="font-semibold"
                        :class="isDarkTheme ? 'text-gray-400' : 'text-gray-500'">EzyVentas</span>
                </p>
            </div>
        </footer>
    </div>
</template>
