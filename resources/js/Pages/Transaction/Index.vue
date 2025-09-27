<script setup>
import { ref, watch, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import { usePermissions } from '@/Composables';

const props = defineProps({
    transactions: Object,
    filters: Object,
});

const confirm = useConfirm();

// composables
const { hasPermission } = usePermissions();

const selectedTransactions = ref([]);
const searchTerm = ref(props.filters.search || '');
const menu = ref();
const selectedTransactionForMenu = ref(null);

const menuItems = computed(() => {
    const transaction = selectedTransactionForMenu.value;
    if (!transaction) return [];

    const canCancel = ['pendiente', 'completado'].includes(transaction.status);
    const canRefund = transaction.status === 'completado';

    return [
        {
            label: 'Ver',
            icon: 'pi pi-eye',
            command: () => router.get(route('transactions.show', selectedTransactionForMenu.value.id)),
            visible: hasPermission('transactions.see_details')
        },
        {
            label: 'Generar devolución',
            icon: 'pi pi-replay',
            disabled: !canRefund,
            command: generateReturn,
            visible: hasPermission('transactions.refund')
        },
        {
            label: 'Imprimir ticket',
            icon: 'pi pi-print'
        },
        {
            separator: true
        },
        {
            label: 'Cancelar venta',
            icon: 'pi pi-times-circle',
            class: 'text-red-500',
            disabled: !canCancel,
            command: cancelSale,
            visible: hasPermission('transactions.cancel')
        },
    ];
});

const toggleMenu = (event, data) => {
    selectedTransactionForMenu.value = data;
    menu.value.toggle(event);
};

const cancelSale = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres cancelar la venta #${selectedTransactionForMenu.value.folio}? Esta acción repondrá el stock de los productos.`,
        header: 'Confirmar Cancelación',
        icon: 'pi pi-exclamation-triangle',
        accept: () => {
            router.patch(route('transactions.cancel', selectedTransactionForMenu.value.id), {}, { preserveScroll: true });
        }
    });
};

const generateReturn = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres generar una devolución para la venta #${selectedTransactionForMenu.value.folio}? Esta acción repondrá el stock de los productos.`,
        header: 'Confirmar Devolución',
        icon: 'pi pi-replay',
        accept: () => {
            router.patch(route('transactions.refund', selectedTransactionForMenu.value.id), {}, { preserveScroll: true });
        }
    });
};

const fetchData = (options = {}) => {
    const queryParams = {
        page: options.page || 1,
        rows: options.rows || props.transactions.per_page,
        sortField: options.sortField || props.filters.sortField,
        sortOrder: options.sortOrder === 1 ? 'asc' : 'desc',
        search: searchTerm.value,
    };
    router.get(route('transactions.index'), queryParams, { preserveState: true, replace: true });
};

const onPage = (event) => fetchData({ page: event.page + 1, rows: event.rows });
const onSort = (event) => fetchData({ sortField: event.sortField, sortOrder: event.sortOrder });
watch(searchTerm, () => fetchData());

const getStatusSeverity = (status) => {
    const map = { completado: 'success', pendiente: 'info', cancelado: 'danger', reembolsado: 'warning' };
    return map[status] || 'secondary';
};

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
};

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
</script>

<template>

    <Head title="Historial de Ventas" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 bg-gray-100 dark:bg-gray-900 min-h-full">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 md:p-6">
                <!-- Header -->
                <div class="mb-6 flex flex-col md:flex-row justify-between items-center gap-4">
                    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Historial de Ventas</h1>
                    <IconField iconPosition="left" class="w-full md:w-1/3">
                        <InputIcon class="pi pi-search"></InputIcon>
                        <InputText v-model="searchTerm" placeholder="Buscar por folio o cliente..." class="w-full" />
                    </IconField>
                </div>

                <!-- Tabla de Transacciones -->
                <DataTable :value="transactions.data" v-model:selection="selectedTransactions" lazy paginator
                    :totalRecords="transactions.total" :rows="transactions.per_page"
                    :rowsPerPageOptions="[20, 50, 100, 200]" dataKey="id" @page="onPage" @sort="onSort" removableSort
                    tableStyle="min-width: 60rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} ventas">
                    <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>
                    <Column field="folio" header="Folio" sortable></Column>
                    <Column field="created_at" header="Fecha y Hora" sortable>
                        <template #body="{ data }"> {{ formatDate(data.created_at) }} </template>
                    </Column>
                    <Column field="customer.name" header="Cliente" sortable></Column>
                    <Column field="channel" header="Canal" sortable>
                        <template #body="{ data }">
                            <span class="capitalize">{{ data.channel.replace(/_/g, ' ') }}</span>
                        </template>
                    </Column>
                    <Column field="total" header="Total" sortable>
                        <template #body="{ data }"> {{ formatCurrency(data.subtotal - data.total_discount) }}
                        </template>
                    </Column>
                    <Column field="status" header="Estatus" sortable>
                        <template #body="{ data }">
                            <Tag :value="data.status" :severity="getStatusSeverity(data.status)" class="capitalize" />
                        </template>
                    </Column>
                    <Column field="user.name" header="Cajero" sortable></Column>
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }"> <Button @click="toggleMenu($event, data)" icon="pi pi-ellipsis-v"
                                text rounded severity="secondary" /> </template>
                    </Column>
                </DataTable>

                <Menu ref="menu" :model="menuItems" :popup="true" />
            </div>
        </div>
    </AppLayout>
</template>