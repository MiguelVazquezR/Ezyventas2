<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import StoreLayout from '@/Layouts/StoreLayout.vue';

const page = usePage();
const store = computed(() => page.props.store || {});

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
                class="inline-flex items-center gap-1.5 text-xs font-medium text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors mb-6">
                <i class="pi pi-arrow-left !text-[10px]" />
                Volver a la tienda
            </Link>

            <div class="bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] p-6 md:p-8">
                <h1 class="text-2xl md:text-3xl font-light tracking-tight text-gray-900 dark:text-white m-0 mb-6">Políticas de la tienda</h1>
                <div class="prose prose-sm max-w-none text-gray-600 dark:text-gray-300 leading-relaxed"
                    v-html="termsPolicy" />
            </div>
        </div>
    </StoreLayout>
</template>
