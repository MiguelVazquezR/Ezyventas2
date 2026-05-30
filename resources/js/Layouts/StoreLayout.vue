<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const store = computed(() => page.props.store || {});

const rootStyles = computed(() => ({
    '--store-primary': store.value.primary_color || '#3B82F6',
    '--store-secondary': store.value.secondary_color || '#1D4ED8',
}));
</script>

<template>
    <div :style="rootStyles" class="min-h-screen bg-gray-50 flex flex-col">
        <!-- Header -->
        <header class="bg-white border-b border-gray-200 sticky top-0 z-40">
            <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <img v-if="store.logo_url" :src="store.logo_url" class="h-10 w-10 object-contain rounded-lg" alt="Logo" />
                    <div>
                        <h1 class="text-lg font-semibold m-0" style="color: var(--store-primary)">{{ store.name || 'Store' }}</h1>
                        <p v-if="store.description" class="text-xs text-gray-500 m-0">{{ store.description }}</p>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="flex-1">
            <slot />
        </main>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="max-w-6xl mx-auto px-4 py-6 text-center">
                <p v-if="store.footer_note" class="text-xs text-gray-500 m-0">{{ store.footer_note }}</p>
                <p v-else class="text-xs text-gray-400 m-0">{{ store.name || 'Store' }} &mdash; Powered by EzyVentas</p>
            </div>
        </footer>
    </div>
</template>
