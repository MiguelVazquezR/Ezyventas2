<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';

const props = defineProps({
    orders: Object,
    filters: Object,
    statuses: Array,
    counts: Object,
});

const toast = useToast();
const activeStatus = ref(props.filters.status || '');

const formatCurrency = (num) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(num || 0);
};

const statusLabelMap = {
    pending: 'Pending',
    reviewed: 'In review',
    in_preparation: 'In preparation',
    ready: 'Ready',
    delivered: 'Delivered',
    cancelled: 'Cancelled',
};

const statusSeverityMap = {
    pending: 'warn',
    reviewed: 'info',
    in_preparation: 'info',
    ready: 'success',
    delivered: 'success',
    cancelled: 'danger',
};

const filterByStatus = (status) => {
    activeStatus.value = status;
    router.get(route('online-store.orders.index'), { status: status || undefined }, { preserveState: true, preserveScroll: true, replace: true });
};

const totalPending = computed(() => props.counts?.pending || 0);
</script>

<template>
    <Head title="Orders" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-[1400px] mx-auto space-y-6">
            <Toast />
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                <div class="mb-8">
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Orders</h1>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                        <span v-if="totalPending > 0" class="w-1.5 h-1.5 rounded-full bg-orange-500 shadow-[0_0_8px_rgba(249,115,22,0.8)] animate-pulse" />
                        {{ totalPending > 0 ? `${totalPending} pending order(s)` : 'Manage your store orders' }}
                    </p>
                </div>

                <!-- Status tabs -->
                <div class="flex flex-wrap gap-2 mb-6">
                    <Button :label="'All'" :severity="!activeStatus ? 'primary' : 'secondary'" :outlined="!!activeStatus" size="small" class="!rounded-full !text-[10px] !uppercase !tracking-widest !font-bold" @click="filterByStatus('')" />
                    <Button v-for="status in statuses" :key="status.value" :label="`${statusLabelMap[status.value]} (${counts?.[status.value] || 0})`" :severity="activeStatus === status.value ? 'primary' : 'secondary'" :outlined="activeStatus !== status.value" size="small" class="!rounded-full !text-[10px] !uppercase !tracking-widest !font-bold" @click="filterByStatus(status.value)" />
                </div>

                <DataTable :value="orders.data" paginator :rows="20" :totalRecords="orders.total" class="w-full"
                    :pt="{ table: { class: '!min-w-full' }, bodyRow: { class: 'dark:!bg-[#1a1a1a] dark:!border-[#3a3a3a] cursor-pointer hover:dark:!bg-[#2a2a2a]' }, headerRow: { class: 'dark:!bg-[#1a1a1a] dark:!border-[#3a3a3a]' } }"
                    @row-click="(e) => $inertia.get(route('online-store.orders.show', e.data.id))">
                    <Column field="formatted_order_number" header="Order #">
                        <template #body="{ data }">
                            <span class="font-mono font-bold text-primary-600 dark:text-primary-400">{{ data.formatted_order_number }}</span>
                        </template>
                    </Column>
                    <Column field="customer_name" header="Customer" sortable>
                        <template #body="{ data }">
                            <div>
                                <p class="font-medium text-sm m-0">{{ data.customer_name }}</p>
                                <p class="text-xs text-gray-400 m-0">{{ data.customer_phone }}</p>
                            </div>
                        </template>
                    </Column>
                    <Column field="total" header="Total">
                        <template #body="{ data }">
                            <span class="font-mono text-sm font-semibold">{{ formatCurrency(data.total) }}</span>
                        </template>
                    </Column>
                    <Column field="delivery_type" header="Type">
                        <template #body="{ data }">
                            <Tag :value="data.delivery_type === 'pickup' ? 'Pickup' : 'Delivery'" :severity="data.delivery_type === 'pickup' ? 'info' : 'warn'" class="!text-[10px] !uppercase !tracking-widest !font-bold" />
                        </template>
                    </Column>
                    <Column field="status" header="Status">
                        <template #body="{ data }">
                            <Tag :value="statusLabelMap[data.status]" :severity="statusSeverityMap[data.status]" class="!text-[10px] !uppercase !tracking-widest !font-bold" />
                        </template>
                    </Column>
                    <Column field="created_at" header="Date">
                        <template #body="{ data }">
                            <span class="text-xs text-gray-500">{{ new Date(data.created_at).toLocaleDateString('es-MX', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) }}</span>
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
