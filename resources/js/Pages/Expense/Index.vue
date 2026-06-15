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
    { label: 'Exportar gastos', icon: 'pi pi-download', command: () => window.location.href = route('import-export.expenses.export') },
]);

const menu = ref();
const selectedExpenseForMenu = ref(null);

const deleteSingleExpense = () => {
    if (!selectedExpenseForMenu.value) return;
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar el gasto con concepto "${selectedExpenseForMenu.value.folio || 'N/A'}"?`,
        header: 'Confirmar eliminación',
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
        header: 'Confirmación de eliminación masiva',
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
    { label: 'Ver detalle', icon: 'pi pi-eye', command: () => router.get(route('expenses.show', selectedExpenseForMenu.value.id)), visible: hasPermission('expenses.see_details') },
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
    return status === 'pagado' ? 'success' : 'warn';
};

const formatDate = (dateString) => {
    const date = new Date(dateString);
    const userTimezoneOffset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() + userTimezoneOffset).toLocaleDateString('es-MX', { year: 'numeric', month: 'short', day: 'numeric' });
};

const formatCurrency = (value) => {
    if (value === null || value === undefined) return '';
    const numberValue = Number(value);
    if (isNaN(numberValue)) return '';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(numberValue);
};

const getPaymentMethodIcon = (method) => {
    const icons = {
        efectivo: 'pi pi-money-bill',
        tarjeta: 'pi pi-credit-card',
        transferencia: 'pi pi-arrows-h',
    };
    return icons[method] || 'pi pi-question-circle';
};

const getOriginLabel = (expense) => {
    if (expense.is_external) {
        return { label: 'Dinero externo', icon: 'pi pi-wallet', severity: 'info', tooltip: 'Gasto con dinero externo. No afecta tu flujo de dinero.' };
    }
    if (expense.payment_method === 'efectivo') {
        return { label: 'Caja del negocio', icon: 'pi pi-inbox', severity: 'success', tooltip: 'Gasto con efectivo de la caja del negocio.' };
    }
    if (expense.bank_account) {
        return { label: 'Cuenta del negocio', icon: 'pi pi-building', severity: 'success', tooltip: 'Gasto con cuenta bancaria del negocio.' };
    }
    return { label: 'Cuenta del negocio', icon: 'pi pi-building', severity: 'success', tooltip: 'Gasto con cuenta bancaria del negocio.' };
};

const onRowClick = (event) => {
    const target = event.originalEvent.target;
    if (target.closest('button') || target.closest('.p-button') || target.closest('.p-checkbox')) {
        return;
    }
    
    if (hasPermission('expenses.see_details')) {
        router.visit(route('expenses.show', event.data.id));
    }
};

// --- TESLA UI PASS-THROUGH (PT) CONFIGURATIONS ---
const menuPt = {
    root: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-2xl !p-2 !shadow-2xl' },
    content: { class: 'dark:hover:!bg-[#1a1a1a] !rounded-xl !transition-colors' },
    label: { class: 'text-sm font-medium text-gray-900 dark:!text-gray-200' },
    icon: { class: 'dark:!text-gray-400 !text-sm mr-3' }
};

const dataTablePt = {
    root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
    headerRow: { class: 'bg-gray-50 dark:bg-[#1a1a1a]' },
    headerCell: { class: 'bg-transparent text-[10px] uppercase tracking-widest text-gray-500 font-bold py-4 px-4 border-b border-gray-100 dark:border-[#3a3a3a]' },
    bodyRow: { class: 'dark:bg-[#232323] hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors text-sm text-gray-700 dark:text-gray-300 group' },
    bodyCell: { class: 'py-4 px-4 border-b border-gray-50 dark:border-[#2a2a2a]' },
    paginator: { root: { class: 'dark:bg-[#1a1a1a] border-t border-gray-100 dark:border-[#3a3a3a] p-3' } }
};

const inputPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2 !text-sm w-full' }
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' },
    icon: { class: '!text-[10px] !mr-1.5' }
};
</script>

<template>
    <Head title="Gastos" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                
                <!-- Header con Título -->
                <div class="mb-8">
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Historial de gastos</h1>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-red-500 shadow-[0_0_8px_rgba(239,68,68,0.8)] animate-pulse"></span>
                        Egresos operativos y administrativos
                    </p>
                </div>

                <!-- Barra de Herramientas de Filtros (Estilo Panel de Control) -->
                <div class="flex flex-col md:flex-row gap-4 items-center justify-between bg-gray-50 dark:bg-[#1a1a1a] p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] mb-6">
                    <IconField iconPosition="left" class="w-full md:w-1/2 lg:w-1/3">
                        <InputIcon class="pi pi-search !text-sm text-gray-400 dark:text-gray-500"></InputIcon>
                        <InputText v-model="searchTerm" placeholder="Buscar por folio, concepto o descripción..." :pt="inputPt" class="!pl-10" />
                    </IconField>
                    
                    <div class="flex items-center gap-2 w-full md:w-auto">
                        <Button v-if="hasPermission('expenses.create')" label="Nuevo gasto"
                            icon="pi pi-plus" @click="router.get(route('expenses.create'))"
                            severity="warning"
                            class="!rounded-xl !text-xs !uppercase !tracking-wider flex-grow md:flex-none" />
                        
                        <Button v-if="hasPermission('expenses.import_export')" icon="pi pi-chevron-down"
                            @click="toggleHeaderMenu" severity="warning" class="!rounded-xl !size-9 !p-0 shrink-0" />
                        
                        <Menu ref="headerMenu" :model="splitButtonItems" :popup="true" :pt="menuPt" />
                    </div>
                </div>

                <!-- Barra de Acciones Masivas Contextual -->
                <div v-if="selectedExpenses.length > 0" class="bg-red-50 dark:bg-red-900/10 border border-red-100 dark:border-red-900/30 rounded-2xl p-3 mb-6 flex flex-col md:flex-row justify-between items-center gap-4 transition-all duration-300">
                    <span class="font-bold text-xs uppercase tracking-widest text-red-700 dark:text-red-300 m-0">
                        <i class="pi pi-check-square mr-1"></i> {{ selectedExpenses.length }} seleccionados
                    </span>
                    <div class="flex items-center gap-2 w-full md:w-auto overflow-x-auto custom-scrollbar pb-1 md:pb-0">
                        <Button v-if="hasPermission('expenses.delete')" @click="deleteSelectedExpenses" label="Eliminar"
                            icon="pi pi-trash" size="small" severity="danger" outlined 
                            class="!rounded-xl !text-xs !uppercase !tracking-wider shrink-0" />
                    </div>
                </div>

                <!-- Tabla de Gastos -->
                <DataTable :value="expenses.data" v-model:selection="selectedExpenses" lazy paginator
                    :totalRecords="expenses.total" :rows="expenses.per_page" :rowsPerPageOptions="[20, 50, 100, 200]"
                    dataKey="id" @page="onPage" @sort="onSort" removableSort tableStyle="min-width: 60rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} gastos"
                    class="cursor-pointer" rowHover @row-click="onRowClick" :pt="dataTablePt">

                    <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>
                    
                    <Column field="folio" header="Concepto" sortable>
                        <template #body="{ data }">
                            <span class="font-mono font-bold dark:text-gray-300">{{ data.folio }}</span>
                        </template>
                    </Column>
                    
                    <Column field="expense_date" header="Fecha" sortable>
                        <template #body="{ data }"> 
                            <span class="text-gray-600 dark:text-gray-400">{{ formatDate(data.expense_date) }}</span> 
                        </template>
                    </Column>
                    
                    <Column field="category.name" header="Categoría" sortable>
                        <template #body="{ data }">
                            <span class="font-medium text-gray-900 dark:text-gray-100">{{ data.category?.name || '--' }}</span>
                        </template>
                    </Column>
                    
                    <Column field="description" header="Descripción" style="max-width: 120px">
                        <template #body="{ data }">
                            <span class="text-gray-500 dark:text-gray-400 truncate max-w-[120px] block" :title="data.description">
                                {{ data.description || '--' }}
                            </span>
                        </template>
                    </Column>

                    <Column field="is_external" header="Origen" sortable>
                        <template #body="{ data }">
                            <Tag :value="getOriginLabel(data).label" :severity="getOriginLabel(data).severity" :pt="tagPt" class="capitalize" v-tooltip.top="getOriginLabel(data).tooltip">
                                <i :class="getOriginLabel(data).icon" class="mr-1"></i>
                            </Tag>
                        </template>
                    </Column>
                    
                    <Column field="amount" header="Monto" sortable>
                        <template #body="{ data }">
                            <span class="font-light tracking-tight text-lg dark:text-white">{{ formatCurrency(data.amount) }}</span>
                        </template>
                    </Column>
                    
                    <Column field="payment_method" header="Método de pago" sortable>
                        <template #body="{ data }">
                            <div class="flex flex-col gap-0.5">
                                <div class="flex items-center gap-2">
                                    <i :class="getPaymentMethodIcon(data.payment_method)" class="!text-[10px] text-gray-400"></i>
                                    <span class="capitalize font-medium text-gray-700 dark:text-gray-300">{{ data.payment_method }}</span>
                                </div>
                                <span v-if="data.bank_account" class="text-[9px] uppercase tracking-widest text-gray-500 dark:text-gray-500 flex items-center gap-1 pl-4" v-tooltip.bottom="data.bank_account.bank_name">
                                    <i class="pi pi-building !text-[8px]"></i> {{ data.bank_account.account_name }}
                                </span>
                            </div>
                        </template>
                    </Column>

                    <Column field="status" header="Estatus" sortable>
                        <template #body="{ data }">
                            <Tag :value="data.status" :severity="getStatusSeverity(data.status)" :pt="tagPt" />
                        </template>
                    </Column>
                    
                    <Column field="user.name" header="Registrado por" sortable>
                        <template #body="{ data }">
                            <span class="text-gray-600 dark:text-gray-400">{{ data.user?.name || 'N/A' }}</span>
                        </template>
                    </Column>
                    
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }"> 
                            <Button @click.stop="toggleMenu($event, data)" icon="pi pi-ellipsis-v" text rounded
                                class="!w-8 !h-8 !text-gray-400 hover:!bg-gray-200 dark:hover:!bg-[#2a2a2a] !transition-colors" aria-haspopup="true" aria-controls="overlay_menu" /> 
                        </template>
                    </Column>
                    
                    <template #empty>
                        <div class="flex flex-col items-center justify-center text-center py-10">
                            <i class="pi pi-arrow-up-right !text-3xl text-gray-400 mb-3"></i>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin resultados</p>
                            <p class="text-xs text-gray-400 mt-1">No hay gastos que coincidan con la búsqueda actual.</p>
                        </div>
                    </template>
                </DataTable>

                <Menu ref="menu" id="overlay_menu" :model="menuItems" :popup="true" :pt="menuPt" />
            </div>
        </div>
        
        <!-- Modal de Importación -->
        <ImportExpensesModal :visible="showImportModal" @update:visible="showImportModal = false" />
    </AppLayout>
</template>