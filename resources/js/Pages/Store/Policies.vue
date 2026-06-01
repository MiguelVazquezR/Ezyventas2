<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import StoreLayout from '@/Layouts/StoreLayout.vue';

const page = usePage();
const store = computed(() => page.props.store || {});
const isDarkTheme = computed(() => store.value.theme_mode === 'dark');

const props = defineProps({
    termsPolicy: String,
});

const slug = computed(() => {
    const parts = window.location.pathname.split('/');
    return parts[2] || '';
});
</script>

<template>
    <Head :title="'Políticas — ' + (store.name || 'Tienda')" />
    <StoreLayout>
        <div class="max-w-3xl mx-auto px-4 md:px-6 py-8">
            <Link :href="route('store.home', { slug: slug })"
                :class="[
                    'inline-flex items-center gap-1.5 text-xs font-medium transition-colors mb-6',
                    isDarkTheme ? 'text-gray-500 hover:text-gray-400' : 'text-gray-400 hover:text-gray-600'
                ]">
                <i class="pi pi-arrow-left !text-[10px]" />
                Volver a la tienda
            </Link>

            <div :class="[
                'rounded-3xl border p-6 md:p-8',
                isDarkTheme ? 'bg-[#232323] border-[#3a3a3a]' : 'bg-white border-gray-100'
            ]">
                <h1 class="text-2xl md:text-3xl font-light tracking-tight m-0 mb-6"
                    :class="isDarkTheme ? 'text-white' : 'text-gray-900'">Políticas de la tienda</h1>
                <div class="prose prose-sm max-w-none leading-relaxed"
                    :class="isDarkTheme ? 'text-gray-300' : 'text-gray-600'"
                    v-html="termsPolicy" />
            </div>
        </div>
    </StoreLayout>
</template>
