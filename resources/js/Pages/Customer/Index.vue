<script setup>
import { ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import ImportCustomersModal from './Partials/ImportCustomersModal.vue';
import CustomerDrawerDetails from './Partials/CustomerDrawerDetails.vue';
import { usePermissions } from '@/Composables';
import { router, usePage } from '@inertiajs/vue3';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    customers: Object,
    filters: Object,
});

const page = usePage();

const confirm = useConfirm();

// composables
const { hasPermission } = usePermissions();

// --- Estado y Lógica ---
const selectedCustomers = ref([]);
const searchTerm = ref(props.filters.search || '');
const showImportModal = ref(false);

// Estado para el Drawer (Panel lateral)
const isDrawerVisible = ref(false);
const selectedCustomerForDrawer = ref(null);

const headerMenu = ref();
const toggleHeaderMenu = (event) => {
    headerMenu.value.toggle(event);
};
const splitButtonItems = ref([
    { label: 'Exportar clientes', icon: 'pi pi-download', command: () => window.location.href = route('import-export.customers.export') },
]);

const menu = ref();
const selectedCustomerForMenu = ref(null);

// --- Lógica de Acciones ---

const deleteSingleCustomer = () => {
    if (!selectedCustomerForMenu.value) return;
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar a ${selectedCustomerForMenu.value.name}?`,
        header: 'Confirmar eliminación',
        icon: 'pi pi-info-circle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'Cancelar',
        accept: () => {
            router.delete(route('customers.destroy', selectedCustomerForMenu.value.id), {
                preserveScroll: true,
                onSuccess: () => {
                    selectedCustomers.value = selectedCustomers.value.filter(c => c.id !== selectedCustomerForMenu.value.id);
                    if (selectedCustomerForDrawer.value?.id === selectedCustomerForMenu.value.id) {
                        isDrawerVisible.value = false;
                    }
                }
            });
        }
    });
};

const deleteSelectedCustomers = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar los ${selectedCustomers.value.length} clientes seleccionados? Esta acción no se puede deshacer.`,
        header: 'Confirmación de eliminación masiva',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'Cancelar',
        accept: () => {
            const idsToDelete = selectedCustomers.value.map(c => c.id);
            router.post(route('customers.batchDestroy'), { ids: idsToDelete }, {
                onSuccess: () => {
                    selectedCustomers.value = []; 
                    isDrawerVisible.value = false;
                },
                preserveScroll: true,
            });
        }
    });
};

const menuItems = ref([
    { label: 'Ver', icon: 'pi pi-eye', command: () => router.get(route('customers.show', selectedCustomerForMenu.value.id)), visible: hasPermission('customers.see_details') },
    { label: 'Editar cliente', icon: 'pi pi-pencil', command: () => router.get(route('customers.edit', selectedCustomerForMenu.value.id)), visible: hasPermission('customers.edit') },
    {
        label: 'Estado de cuenta',
        icon: 'pi pi-file-pdf',
        command: () => window.open(route('customers.printStatement', selectedCustomerForMenu.value.id), '_blank'),
        visible: hasPermission('customers.see_details')
    },
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: deleteSingleCustomer, visible: hasPermission('customers.delete') },
]);

const toggleMenu = (event, data) => {
    selectedCustomerForMenu.value = data;
    menu.value.toggle(event);
};

// --- Lógica de la Tabla ---
const fetchData = (options = {}) => {
    const queryParams = {
        page: options.page || 1,
        rows: options.rows || props.customers.per_page,
        sortField: options.sortField || props.filters.sortField,
        sortOrder: options.sortOrder === 1 ? 'asc' : 'desc',
        search: searchTerm.value,
    };
    router.get(route('customers.index'), queryParams, { preserveState: true, replace: true });
};

const onPage = (event) => fetchData({ page: event.page + 1, rows: event.rows });
const onSort = (event) => fetchData({ sortField: event.sortField, sortOrder: event.sortOrder });
watch(searchTerm, () => fetchData());

// --- Helpers de Formato ---
const getBalanceClass = (balance) => {
    if (balance > 0) return 'text-green-500';
    if (balance < 0) return 'text-red-500';
    return 'text-gray-500';
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
};

const onRowClick = (event) => {
    const target = event.originalEvent.target;
    if (target.closest('button') || target.closest('.p-button') || target.closest('.p-checkbox')) {
        return;
    }
    
    const clickAction = page.props.auth.preferences?.customer_table_row_click_action || 'Vista lateral con algunos detalles';

    if (clickAction === 'Redirección a vista de detalles') {
        router.get(route('customers.show', event.data.id));
    } else {
        selectedCustomerForDrawer.value = event.data;
        isDrawerVisible.value = true;
    }
};

const goToDetails = (id) => {
    router.visit(route('customers.show', id));
};

const goToEdit = (id) => {
    router.visit(route('customers.edit', id));
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

const drawerPt = {
    root: { class: 'dark:!bg-[#232323] !border-l-gray-100 dark:!border-l-[#3a3a3a]' },
    header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-5' },
    title: { class: 'text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0' },
    content: { class: 'dark:bg-[#232323] p-0 custom-scrollbar' }, 
    closeButton: { class: 'hover:bg-gray-100 dark:hover:bg-[#1a1a1a] transition-colors rounded-full w-8 h-8 flex items-center justify-center' },
    closeButtonIcon: { class: 'dark:text-gray-400 !text-sm' },
    mask: { class: 'backdrop-blur-sm bg-gray-900/40 dark:bg-black/60' }
};
</script>

<template>
    <Head title="Clientes" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                
                <!-- Header con Título -->
                <div class="mb-8">
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Directorio de clientes</h1>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.8)] animate-pulse"></span>
                        Gestión de cuentas y créditos
                    </p>
                </div>

                <!-- Barra de Herramientas de Filtros (Estilo Panel de Control) -->
                <div class="flex flex-col md:flex-row gap-4 items-center justify-between bg-gray-50 dark:bg-[#1a1a1a] p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] mb-6">
                    <IconField iconPosition="left" class="w-full md:w-1/2 lg:w-1/3">
                        <InputIcon class="pi pi-search !text-sm text-gray-400 dark:text-gray-500"></InputIcon>
                        <InputText v-model="searchTerm" placeholder="Buscar por nombre, empresa o email..." :pt="inputPt" class="!pl-10" />
                    </IconField>
                    
                    <div class="flex items-center gap-2 w-full md:w-auto">
                        <Button v-if="hasPermission('customers.create')" label="Nuevo cliente"
                            icon="pi pi-plus" @click="router.get(route('customers.create'))"
                            severity="warning"
                            class="!rounded-xl !text-xs !uppercase !tracking-wider flex-grow md:flex-none" />
                        
                        <Button v-if="hasPermission('customers.import_export')" icon="pi pi-chevron-down"
                            @click="toggleHeaderMenu" severity="warning" class="!rounded-xl !size-9 !p-0 shrink-0" />
                        
                        <Menu ref="headerMenu" :model="splitButtonItems" :popup="true" :pt="menuPt" />
                    </div>
                </div>

                <!-- Barra de Acciones Masivas Contextual -->
                <div v-if="selectedCustomers.length > 0" class="bg-blue-50 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-900/30 rounded-2xl p-3 mb-6 flex flex-col md:flex-row justify-between items-center gap-4 transition-all duration-300">
                    <span class="font-bold text-xs uppercase tracking-widest text-blue-700 dark:text-blue-300 m-0">
                        <i class="pi pi-check-square mr-1"></i> {{ selectedCustomers.length }} seleccionados
                    </span>
                    <div class="flex items-center gap-2 w-full md:w-auto overflow-x-auto custom-scrollbar pb-1 md:pb-0">
                        <Button v-if="hasPermission('customers.delete')" @click="deleteSelectedCustomers" label="Eliminar"
                            icon="pi pi-trash" size="small" severity="danger" outlined 
                            class="!rounded-xl !text-xs !uppercase !tracking-wider shrink-0" />
                    </div>
                </div>

                <!-- Tabla de Clientes -->
                <DataTable :value="customers.data" v-model:selection="selectedCustomers" lazy paginator
                    :totalRecords="customers.total" :rows="customers.per_page" :rowsPerPageOptions="[20, 50, 100, 200]"
                    dataKey="id" @page="onPage" @sort="onSort" removableSort tableStyle="min-width: 60rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} clientes"
                    class="cursor-pointer" rowHover @row-click="onRowClick" :pt="dataTablePt">

                    <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>
                    
                    <Column field="name" header="Nombre" sortable>
                        <template #body="{ data }">
                            <div class="flex flex-col gap-0.5 items-start justify-center">
                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ data.name }}</span>
                                <span v-if="data.company_name" class="text-[10px] text-gray-500 uppercase tracking-widest flex items-center gap-1">
                                    <i class="pi pi-building !text-[8px]"></i> {{ data.company_name }}
                                </span>
                            </div>
                        </template>
                    </Column>
                    
                    <Column field="phone" header="Contacto" sortable>
                        <template #body="{ data }">
                            <div class="flex flex-col gap-1">
                                <span v-if="data.phone" class="text-xs text-gray-600 dark:text-gray-400 flex items-center gap-1.5"><i class="pi pi-phone !text-[10px]"></i>{{ data.phone }}</span>
                                <span v-if="data.email" class="text-xs text-gray-600 dark:text-gray-400 flex items-center gap-1.5"><i class="pi pi-envelope !text-[10px]"></i>{{ data.email }}</span>
                                <span v-if="!data.phone && !data.email" class="text-xs text-gray-500 italic">No registrado</span>
                            </div>
                        </template>
                    </Column>
                    
                    <Column v-if="hasPermission('customers.see_financial_info')" field="balance" header="Saldo" sortable>
                        <template #body="{ data }">
                            <span :class="getBalanceClass(data.balance)" class="font-light tracking-tight text-lg">
                                {{ formatCurrency(data.balance) }}
                            </span>
                        </template>
                    </Column>
                    
                    <Column v-if="hasPermission('customers.see_financial_info')" field="layaway_items_quantity_sum" header="Apartados" sortable>
                        <template #body="{ data }">
                            <div class="flex items-center gap-1">
                                <span v-if="data.layaway_items_quantity_sum > 0" class="font-medium text-purple-600 dark:text-purple-400 flex items-center gap-1">
                                    {{ data.layaway_items_quantity_sum }} <span class="text-[10px] uppercase tracking-widest">unid.</span>
                                </span>
                                <span v-else class="text-gray-400 dark:text-gray-500 font-mono">0</span>
                            </div>
                        </template>
                    </Column>
                    
                    <Column v-if="hasPermission('customers.see_financial_info')" field="credit_limit" header="Límite crédito" sortable>
                        <template #body="{ data }">
                            <span class="font-mono text-sm text-gray-700 dark:text-gray-300">
                                {{ formatCurrency(data.credit_limit) }}
                            </span>
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
                            <i class="pi pi-users !text-3xl text-gray-400 mb-3"></i>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin resultados</p>
                            <p class="text-xs text-gray-400 mt-1">No hay clientes registrados o que coincidan con la búsqueda.</p>
                        </div>
                    </template>
                </DataTable>

                <Menu ref="menu" id="overlay_menu" :model="menuItems" :popup="true" :pt="menuPt" />
            </div>
        </div>

        <!-- Drawer de Detalles del Cliente Aislado -->
        <Drawer v-model:visible="isDrawerVisible" position="right" class="w-full md:!w-[30rem]" :pt="drawerPt">
            <template #header>
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                        <i class="pi pi-user text-blue-500 !text-sm"></i>
                    </div>
                    <span class="text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0">Detalles rápidos</span>
                </div>
            </template>
            
            <CustomerDrawerDetails 
                v-if="selectedCustomerForDrawer"
                :customer="selectedCustomerForDrawer"
                :can-see-details="hasPermission('customers.see_details')"
                :can-edit="hasPermission('customers.edit')"
                :can-see-financials="hasPermission('customers.see_financial_info')"
                @go-to-details="goToDetails(selectedCustomerForDrawer.id)"
                @go-to-edit="goToEdit(selectedCustomerForDrawer.id)"
            />
        </Drawer>

        <!-- Modal de Importación -->
        <ImportCustomersModal :visible="showImportModal" @update:visible="showImportModal = false" />
    </AppLayout>
</template>