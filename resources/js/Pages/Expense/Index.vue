<script setup>
import { ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import ImportExpensesModal from './Partials/ImportExpensesModal.vue';
import { usePermissions } from '@/Composables';

const props = defineProps({
    expenses: Object,
    filters: Object,
});

const confirm = useConfirm();

// composables
const { hasPermission } = usePermissions();

const selectedExpenses = ref([]);
const searchTerm = ref(props.filters.search || '');
const showImportModal = ref(false);

const headerMenu = ref();
const toggleHeaderMenu = (event) => {
    headerMenu.value.toggle(event);
};
const splitButtonItems = ref([
    //{ label: 'Importar Gastos', icon: 'pi pi-upload', command: () => showImportModal.value = true },
    { label: 'Exportar Gastos', icon: 'pi pi-download', command: () => window.location.href = route('import-export.expenses.export') },
]);

const menu = ref();
const selectedExpenseForMenu = ref(null);

const deleteSingleExpense = () => {
    if (!selectedExpenseForMenu.value) return;
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar el gasto con concepto "${selectedExpenseForMenu.value.folio || 'N/A'}"?`,
        header: 'Confirmar Eliminación',
        icon: 'pi pi-info-circle',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(route('expenses.destroy', selectedExpenseForMenu.value.id), {
                preserveScroll: true,
                onSuccess: () => {
                    selectedExpenses.value = selectedExpenses.value.filter(e => e.id !== selectedExpenseForMenu.value.id);
                }
            });
        }
    });
};

const deleteSelectedExpenses = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar los ${selectedExpenses.value.length} gastos seleccionados?`,
        header: 'Confirmación de Eliminación Masiva',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'Cancelar',
        accept: () => {
            const idsToDelete = selectedExpenses.value.map(e => e.id);
            router.post(route('expenses.batchDestroy'), { ids: idsToDelete }, {
                onSuccess: () => selectedExpenses.value = [],
                preserveScroll: true,
            });
        }
    });
};

const menuItems = ref([
    { label: 'Ver', icon: 'pi pi-eye', command: () => router.get(route('expenses.show', selectedExpenseForMenu.value.id)), visible: hasPermission('expenses.see_details') },
    { label: 'Editar gasto', icon: 'pi pi-pencil', command: () => router.get(route('expenses.edit', selectedExpenseForMenu.value.id)), visible: hasPermission('expenses.edit') },
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: deleteSingleExpense, visible: hasPermission('expenses.delete') },
]);

const toggleMenu = (event, data) => {
    selectedExpenseForMenu.value = data;
    menu.value.toggle(event);
};

const fetchData = (options = {}) => {
    const queryParams = {
        page: options.page || 1,
        rows: options.rows || props.expenses.per_page,
        sortField: options.sortField || props.filters.sortField,
        sortOrder: options.sortOrder === 1 ? 'asc' : 'desc',
        search: searchTerm.value,
    };
    router.get(route('expenses.index'), queryParams, { preserveState: true, replace: true });
};

const onPage = (event) => fetchData({ page: event.page + 1, rows: event.rows });
const onSort = (event) => fetchData({ sortField: event.sortField, sortOrder: event.sortOrder });
watch(searchTerm, () => fetchData());

const getStatusSeverity = (status) => {
    return status === 'pagado' ? 'success' : 'warning';
};

const formatDate = (dateString) => {
    const date = new Date(dateString);
    const userTimezoneOffset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() + userTimezoneOffset).toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' });
};

// NUEVA FUNCIÓN: Devuelve el icono correspondiente al método de pago
const getPaymentMethodIcon = (method) => {
    const icons = {
        efectivo: 'pi pi-money-bill',
        tarjeta: 'pi pi-credit-card',
        transferencia: 'pi pi-arrows-h',
    };
    return icons[method] || 'pi pi-question-circle';
};
</script>

<template>

    <Head title="Gastos" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 bg-gray-100 dark:bg-gray-900 min-h-full">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 md:p-6">
                <!-- Header -->
                <div class="mb-6 flex flex-col md:flex-row justify-between items-center gap-4">
                    <IconField iconPosition="left" class="w-full md:w-1/3">
                        <InputIcon class="pi pi-search"></InputIcon>
                        <InputText v-model="searchTerm" placeholder="Buscar por concepto o descripción..."
                            class="w-full" />
                    </IconField>
                    <div class="flex items-center gap-2">
                        <ButtonGroup>
                            <Button v-if="hasPermission('expenses.create')" label="Nuevo gasto" icon="pi pi-plus"
                                @click="router.get(route('expenses.create'))" severity="warning" />
                            <Button v-if="hasPermission('expenses.import_export')" icon="pi pi-chevron-down"
                                @click="toggleHeaderMenu" severity="warning" />
                        </ButtonGroup>
                        <Menu ref="headerMenu" :model="splitButtonItems" :popup="true" />
                    </div>
                </div>

                <!-- Barra de Acciones Masivas -->
                <div v-if="selectedExpenses.length > 0"
                    class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg p-2 mb-4 flex justify-between items-center">
                    <span class="font-semibold text-sm text-blue-800 dark:text-blue-200">{{ selectedExpenses.length }}
                        gasto(s) seleccionado(s)</span>
                    <Button v-if="hasPermission('expenses.delete')" @click="deleteSelectedExpenses" label="Eliminar"
                        icon="pi pi-trash" size="small" severity="danger" outlined />
                </div>

                <!-- Tabla de Gastos -->
                <DataTable :value="expenses.data" v-model:selection="selectedExpenses" lazy paginator
                    :totalRecords="expenses.total" :rows="expenses.per_page" :rowsPerPageOptions="[20, 50, 100, 200]"
                    dataKey="id" @page="onPage" @sort="onSort" removableSort tableStyle="min-width: 60rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} gastos">
                    <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>
                    <Column field="folio" header="Concepto" sortable></Column>
                    <Column field="expense_date" header="Fecha" sortable>
                        <template #body="{ data }"> {{ formatDate(data.expense_date) }} </template>
                    </Column>
                    <Column field="category.name" header="Categoría" sortable></Column>
                    <Column field="description" header="Descripción"></Column>
                    <Column field="amount" header="Monto" sortable>
                        <template #body="{ data }"> {{ new Intl.NumberFormat('es-MX', {
                            style: 'currency', currency:
                                'MXN'
                        }).format(data.amount) }} </template>
                    </Column>
                    
                    <!-- INICIA NUEVA COLUMNA -->
                    <Column field="payment_method" header="Método de pago" sortable>
                        <template #body="{ data }">
                            <div class="flex flex-col">
                                <div class="flex items-center gap-2">
                                    <i :class="getPaymentMethodIcon(data.payment_method)" class="text-gray-500"></i>
                                    <span class="capitalize font-medium">{{ data.payment_method }}</span>
                                </div>
                                <small v-if="data.bank_account" class="text-gray-500 dark:text-gray-400 mt-1 pl-1">
                                    {{ data.bank_account.account_name }}
                                </small>
                            </div>
                        </template>
                    </Column>
                    <!-- TERMINA NUEVA COLUMNA -->

                    <Column field="status" header="Estatus" sortable>
                        <template #body="{ data }">
                            <Tag :value="data.status" :severity="getStatusSeverity(data.status)" />
                        </template>
                    </Column>
                    <Column field="user.name" header="Registrado por" sortable></Column>
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }"> <Button @click="toggleMenu($event, data)" icon="pi pi-ellipsis-v"
                                text rounded severity="secondary" /> </template>
                    </Column>
                    <template #empty>
                        <div class="text-center text-gray-500 py-4">
                            No hay gastos registrados.
                        </div>
                    </template>
                </DataTable>

                <Menu ref="menu" :model="menuItems" :popup="true" />
            </div>
        </div>
        <!-- Modal de Importación -->
        <ImportExpensesModal :visible="showImportModal" @update:visible="showImportModal = false" />
    </AppLayout>
</template>