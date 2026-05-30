<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';

const props = defineProps({
    products: Object,
});

const toast = useToast();

const formatCurrency = (num) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(num || 0);
};

const toggleVisibility = (product) => {
    router.put(route('online-store.products.toggle', product.id), {}, {
        preserveScroll: true,
        onSuccess: () => toast.add({ severity: 'success', summary: 'Updated', detail: 'Product visibility toggled.', life: 2000 }),
    });
};

const toggleFeatured = (product) => {
    router.put(route('online-store.products.toggle-featured', product.id), {}, {
        preserveScroll: true,
        onSuccess: () => toast.add({ severity: 'success', summary: 'Updated', detail: 'Featured status toggled.', life: 2000 }),
    });
};
</script>

<template>
    <Head title="Store Products" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-[1400px] mx-auto space-y-6">
            <Toast />
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
                    <div>
                        <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Store products</h1>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2">Toggle which products appear in your online store</p>
                    </div>
                </div>

                <DataTable :value="products.data" paginator :rows="20" :totalRecords="products.total" class="w-full"
                    :pt="{ table: { class: '!min-w-full' }, bodyRow: { class: 'dark:!bg-[#1a1a1a] dark:!border-[#3a3a3a]' }, headerRow: { class: 'dark:!bg-[#1a1a1a] dark:!border-[#3a3a3a]' } }">
                    <Column field="name" header="Product" sortable>
                        <template #body="{ data }">
                            <div class="flex items-center gap-3">
                                <img v-if="data.media?.length" :src="data.media[0].original_url" class="w-10 h-10 object-cover rounded-lg" />
                                <div>
                                    <p class="font-medium text-gray-900 dark:text-white text-sm m-0">{{ data.name }}</p>
                                    <p class="text-xs text-gray-400 m-0">{{ data.category?.name || 'Uncategorized' }}</p>
                                </div>
                            </div>
                        </template>
                    </Column>
                    <Column field="selling_price" header="POS price" sortable>
                        <template #body="{ data }">
                            <span class="font-mono text-sm">{{ formatCurrency(data.selling_price) }}</span>
                        </template>
                    </Column>
                    <Column field="online_price" header="Online price">
                        <template #body="{ data }">
                            <span v-if="data.online_price" class="font-mono text-sm font-semibold text-primary-600 dark:text-primary-400">{{ formatCurrency(data.online_price) }}</span>
                            <span v-else class="text-xs text-gray-400">Same as POS</span>
                        </template>
                    </Column>
                    <Column field="show_online" header="Online">
                        <template #body="{ data }">
                            <Tag :value="data.show_online ? 'Visible' : 'Hidden'" :severity="data.show_online ? 'success' : 'secondary'" class="!text-[10px] !uppercase !tracking-widest !font-bold" />
                        </template>
                    </Column>
                    <Column field="is_featured" header="Featured">
                        <template #body="{ data }">
                            <i v-if="data.is_featured" class="pi pi-star-fill text-yellow-500" />
                            <i v-else class="pi pi-star text-gray-300 dark:text-gray-600" />
                        </template>
                    </Column>
                    <Column header="Actions" class="!text-right">
                        <template #body="{ data }">
                            <div class="flex items-center gap-2 justify-end">
                                <Button icon="pi pi-eye" text rounded size="small" @click="toggleVisibility(data)" v-tooltip.top="data.show_online ? 'Hide from store' : 'Show in store'" />
                                <Button icon="pi pi-star" text rounded size="small" @click="toggleFeatured(data)" v-tooltip.top="data.is_featured ? 'Remove featured' : 'Mark featured'" />
                                <Button as="a" :href="route('online-store.products.edit', data.id)" icon="pi pi-pencil" text rounded size="small" v-tooltip.top="'Edit store settings'" />
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
