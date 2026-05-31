<script setup>
import { computed } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';

const page = usePage();
const store = computed(() => page.props.store || {});

const slug = computed(() => {
    const parts = window.location.pathname.split('/');
    return parts[2] || '';
});

const rootStyles = computed(() => ({
    '--store-primary': store.value.primary_color || '#3B82F6',
    '--store-primary-glow': store.value.primary_color
        ? store.value.primary_color + '33'
        : 'rgba(59,130,246,0.2)',
    '--store-secondary': store.value.secondary_color || '#1D4ED8',
}));
</script>

<template>
    <div :style="rootStyles" class="min-h-screen bg-gray-50 dark:bg-[#1a1a1a] flex flex-col">
        <!-- Header -->
        <header class="sticky top-0 z-40 bg-white/80 dark:bg-[#232323]/90 backdrop-blur-xl border-b border-gray-100 dark:border-[#3a3a3a]">
            <div class="max-w-7xl mx-auto px-4 md:px-6 py-3 flex items-center justify-between">
                <Link :href="route('store.home', { slug: slug })" class="flex items-center gap-3 group">
                    <img v-if="store.logo_url" :src="store.logo_url" class="h-12 max-w-[120px] object-contain object-left" alt="Logo" />
                    <div v-else class="h-10 w-10 rounded-xl flex items-center justify-center text-white font-bold text-sm shrink-0" :style="{ background: 'var(--store-primary)' }">
                        {{ (store.name || 'T').charAt(0).toUpperCase() }}
                    </div>
                    <div>
                        <h1 class="text-base font-semibold m-0 text-gray-900 dark:text-white transition-colors" :style="{ color: 'var(--store-primary)' }">
                            {{ store.name || 'Tienda' }}
                        </h1>
                        <p v-if="store.description" class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 m-0">
                            {{ store.description }}
                        </p>
                    </div>
                </Link>
                <!-- Cart icon -->
                <Link :href="route('store.cart', { slug: slug })"
                    class="relative w-10 h-10 flex items-center justify-center rounded-full border border-gray-100 dark:border-[#3a3a3a] bg-white dark:bg-[#232323] hover:border-gray-300 dark:hover:border-gray-600 transition-colors">
                    <i class="pi pi-shopping-cart text-gray-600 dark:text-gray-300 !text-sm" />
                </Link>
            </div>
        </header>

        <!-- Main Content -->
        <main class="flex-1">
            <slot />
        </main>

        <!-- Footer -->
        <footer class="border-t border-gray-100 dark:border-[#3a3a3a] bg-white dark:bg-[#232323] mt-auto">
            <div class="max-w-7xl mx-auto px-4 md:px-6 py-6 text-center">
                <p v-if="store.footer_note" class="text-[11px] text-gray-500 dark:text-gray-400 m-0 leading-relaxed">{{ store.footer_note }}</p>
                <p v-else class="text-[11px] text-gray-400 dark:text-gray-500 m-0">
                    {{ store.name || 'Tienda' }} &mdash; Desarrollado por <span class="font-semibold text-gray-500 dark:text-gray-400">EzyVentas</span>
                </p>
            </div>
        </footer>
    </div>
</template>
