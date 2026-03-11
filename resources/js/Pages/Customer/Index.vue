<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import ImportCustomersModal from './Partials/ImportCustomersModal.vue';
import { usePermissions } from '@/Composables';

const props = defineProps({
    customers: Object,
    filters: Object,
});

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
    //{ label: 'Importar Clientes', icon: 'pi pi-upload', command: () => showImportModal.value = true },
    { label: 'Exportar Clientes', icon: 'pi pi-download', command: () => window.location.href = route('import-export.customers.export') },
]);

const menu = ref();
const selectedCustomerForMenu = ref(null);

// --- Lógica de Acciones ---

const deleteSingleCustomer = () => {
    if (!selectedCustomerForMenu.value) return;
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar a ${selectedCustomerForMenu.value.name}?`,
        header: 'Confirmar Eliminación',
        icon: 'pi pi-info-circle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'Cancelar',
        accept: () => {
            router.delete(route('customers.destroy', selectedCustomerForMenu.value.id), {
                preserveScroll: true,
                onSuccess: () => {
                    // Refrescar la selección si el cliente eliminado estaba seleccionado
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
        header: 'Confirmación de Eliminación Masiva',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'Cancelar',
        accept: () => {
            const idsToDelete = selectedCustomers.value.map(c => c.id);
            router.post(route('customers.batchDestroy'), { ids: idsToDelete }, {
                onSuccess: () => {
                    selectedCustomers.value = []; // Limpiar la selección
                    isDrawerVisible.value = false;
                },
                preserveScroll: true,
            });
        }
    });
};

// --- MODIFICADO: Añadido "Estado de Cuenta" ---
const menuItems = ref([
    { label: 'Ver', icon: 'pi pi-eye', command: () => router.get(route('customers.show', selectedCustomerForMenu.value.id)), visible: hasPermission('customers.see_details') },
    { label: 'Editar cliente', icon: 'pi pi-pencil', command: () => router.get(route('customers.edit', selectedCustomerForMenu.value.id)), visible: hasPermission('customers.edit') },
    {
        label: 'Estado de cuenta',
        icon: 'pi pi-file-pdf',
        command: () => window.open(route('customers.printStatement', selectedCustomerForMenu.value.id), '_blank'),
        visible: hasPermission('customers.see_details')
    },
    // { label: 'Registrar Venta', icon: 'pi pi-shopping-cart', visible: hasPermission('customers.store_sale') },
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
    if (balance > 0) return 'text-green-600 dark:text-green-400';
    if (balance < 0) return 'text-red-600 dark:text-red-400';
    return 'text-gray-600 dark:text-gray-400';
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
};

const formatAddress = (address) => {
    if (!address) return 'No registrada';
    if (typeof address === 'string') return address;
    
    // Si es un objeto JSON (como se guarda usualmente)
    const parts = [];
    if (address.street) parts.push(address.street);
    if (address.exterior_number) parts.push(address.exterior_number);
    if (address.neighborhood) parts.push(address.neighborhood);
    if (address.city) parts.push(address.city);
    if (address.state) parts.push(address.state);
    
    return parts.length > 0 ? parts.join(', ') : 'No registrada';
};

const onRowClick = (event) => {
    // Evitamos navegar si se hizo clic en el botón del menú (acciones)
    const target = event.originalEvent.target;
    if (target.closest('button') || target.closest('.p-button') || target.closest('.p-checkbox')) {
        return;
    }
    
    // Abrir Drawer en lugar de navegar de inmediato
    selectedCustomerForDrawer.value = event.data;
    isDrawerVisible.value = true;
};
</script>

<template>
    <AppLayout title="Clientes">
        <div class="p-4 md:p-6 lg:p-8 bg-gray-100 dark:bg-gray-900 min-h-full">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 md:p-6">
                <!-- Header -->
                <div class="mb-6 flex flex-col md:flex-row justify-between items-center gap-4">
                    <IconField iconPosition="left" class="w-full md:w-1/3">
                        <InputIcon class="pi pi-search"></InputIcon>
                        <InputText v-model="searchTerm" placeholder="Buscar por nombre, empresa, email..."
                            class="w-full" />
                    </IconField>
                    <div class="flex items-center gap-2">
                        <ButtonGroup>
                            <Button v-if="hasPermission('customers.create')" label="Nuevo cliente" icon="pi pi-plus"
                                @click="router.get(route('customers.create'))" severity="warning" />
                            <Button v-if="hasPermission('customers.import_export')" icon="pi pi-chevron-down"
                                @click="toggleHeaderMenu" severity="warning" />
                        </ButtonGroup>
                        <Menu ref="headerMenu" :model="splitButtonItems" :popup="true" />
                    </div>
                </div>

                <!-- Barra de Acciones Masivas -->
                <div v-if="selectedCustomers.length > 0"
                    class="bg-gray-50 dark:bg-gray-900/20 border border-gray-200 dark:border-gray-700 rounded-lg p-2 mb-4 flex justify-between items-center">
                    <span class="font-semibold text-sm text-[#373737] dark:text-gray-200">{{ selectedCustomers.length }}
                        cliente(s) seleccionado(s)</span>
                    <Button v-if="hasPermission('customers.delete')" @click="deleteSelectedCustomers" label="Eliminar"
                        icon="pi pi-trash" size="small" severity="danger" outlined />
                </div>

                <!-- Tabla de Clientes -->
                <DataTable :value="customers.data" v-model:selection="selectedCustomers" lazy paginator
                    :totalRecords="customers.total" :rows="customers.per_page" :rowsPerPageOptions="[20, 50, 100, 200]"
                    dataKey="id" @page="onPage" @sort="onSort" removableSort tableStyle="min-width: 60rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} clientes"
                    rowHover
                    @row-click="onRowClick"
                    class="cursor-pointer">
                    <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>
                    <Column field="name" header="Nombre" sortable>
                        <template #body="{ data }">
                            <div>
                                <p class="font-semibold m-0">{{ data.name }}</p>
                                <p v-if="data.company_name" class="text-xs text-gray-500">{{ data.company_name }}</p>
                            </div>
                        </template>
                    </Column>
                    <Column field="phone" header="Contacto" sortable>
                        <template #body="{ data }">
                            <div>
                                <p v-if="data.phone" class="m-0 text-sm"><i class="pi pi-phone !text-xs mr-2 text-gray-400"></i>{{ data.phone }}</p>
                                <p v-if="data.email" class="m-0 text-sm"><i class="pi pi-envelope !text-xs mr-2 text-gray-400"></i>{{ data.email }}</p>
                            </div>
                        </template>
                    </Column>
                    <Column v-if="hasPermission('customers.see_financial_info')" field="balance" header="Saldo"
                        sortable>
                        <template #body="{ data }">
                            <span :class="getBalanceClass(data.balance)" class="font-mono font-semibold">
                                {{ formatCurrency(data.balance) }}
                            </span>
                        </template>
                    </Column>
                    <Column v-if="hasPermission('customers.see_financial_info')" field="layaway_items_quantity_sum"
                        header="Apartados" sortable>
                        <template #body="{ data }">
                            <span v-if="data.layaway_items_quantity_sum > 0"
                                class="font-semibold text-indigo-600 dark:text-indigo-400">
                                {{ data.layaway_items_quantity_sum }}
                                <span class="text-xs">unidades</span>
                            </span>
                            <span v-else class="text-gray-400 dark:text-gray-500">
                                0
                            </span>
                        </template>
                    </Column>
                    <Column v-if="hasPermission('customers.see_financial_info')" field="credit_limit"
                        header="Límite de crédito" sortable>
                        <template #body="{ data }">
                            {{ formatCurrency(data.credit_limit) }}
                        </template>
                    </Column>
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }"> 
                            <Button @click="toggleMenu($event, data)" icon="pi pi-ellipsis-v" text rounded severity="secondary" /> 
                        </template>
                    </Column>
                    <template #empty>
                        <div class="text-center text-gray-500 py-4">
                            No hay clientes registrados.
                        </div>
                    </template>
                </DataTable>

                <Menu ref="menu" :model="menuItems" :popup="true" />
            </div>
        </div>

        <!-- Drawer de Detalles del Cliente -->
        <Drawer v-model:visible="isDrawerVisible" position="right" class="w-full md:!w-[30rem]">
            <template #header>
                <div class="flex items-center gap-2">
                    <i class="pi pi-user text-xl text-gray-600 dark:text-gray-300"></i>
                    <span class="font-bold text-xl text-gray-800 dark:text-gray-100">Detalles Rápidos</span>
                </div>
            </template>
            
            <div v-if="selectedCustomerForDrawer" class="flex flex-col h-full pt-4">
                <div class="flex-grow space-y-6 overflow-y-auto pr-2 pb-6">
                    
                    <!-- Info Header -->
                    <div class="flex items-center gap-4">
                        <Avatar 
                            :label="selectedCustomerForDrawer.name ? selectedCustomerForDrawer.name.substring(0, 1).toUpperCase() : 'C'" 
                            size="xlarge" 
                            shape="circle" 
                            class="!bg-primary-100 !text-primary-700 font-bold text-2xl" 
                        />
                        <div>
                            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 m-0">{{ selectedCustomerForDrawer.name }}</h2>
                            <p v-if="selectedCustomerForDrawer.company_name" class="text-sm text-gray-500 m-0 mt-1">
                                <i class="pi pi-building !text-xs mr-1"></i> {{ selectedCustomerForDrawer.company_name }}
                            </p>
                        </div>
                    </div>

                    <!-- Contact Info -->
                    <div class="space-y-4 bg-gray-50 dark:bg-gray-800/60 p-4 rounded-xl border border-gray-100 dark:border-gray-700">
                        <h3 class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider m-0">Información de Contacto</h3>
                        
                        <div class="flex items-start gap-3">
                            <div class="mt-1 bg-white dark:bg-gray-700 p-1.5 rounded-md shadow-sm border border-gray-100 dark:border-gray-600">
                                <i class="pi pi-phone text-gray-500 dark:text-gray-400"></i>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-gray-800 dark:text-gray-200 m-0">
                                    {{ selectedCustomerForDrawer.phone || 'No registrado' }}
                                </p>
                                <span class="text-xs text-gray-500">Teléfono principal</span>
                            </div>
                        </div>
                        
                        <div class="flex items-start gap-3">
                            <div class="mt-1 bg-white dark:bg-gray-700 p-1.5 rounded-md shadow-sm border border-gray-100 dark:border-gray-600">
                                <i class="pi pi-envelope text-gray-500 dark:text-gray-400"></i>
                            </div>
                            <div class="break-all">
                                <p class="text-sm font-semibold text-gray-800 dark:text-gray-200 m-0">
                                    {{ selectedCustomerForDrawer.email || 'No registrado' }}
                                </p>
                                <span class="text-xs text-gray-500">Correo electrónico</span>
                            </div>
                        </div>
                        
                        <div class="flex items-start gap-3">
                            <div class="mt-1 bg-white dark:bg-gray-700 p-1.5 rounded-md shadow-sm border border-gray-100 dark:border-gray-600">
                                <i class="pi pi-map-marker text-gray-500 dark:text-gray-400"></i>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200 m-0 leading-tight">
                                    {{ formatAddress(selectedCustomerForDrawer.address) }}
                                </p>
                                <span class="text-xs text-gray-500">Dirección</span>
                            </div>
                        </div>
                    </div>

                    <!-- Financial Info -->
                    <div v-if="hasPermission('customers.see_financial_info')" class="space-y-3 bg-blue-50 dark:bg-blue-900/20 p-4 rounded-xl border border-blue-100 dark:border-blue-800/40">
                        <h3 class="text-xs font-bold text-blue-800 dark:text-blue-300 uppercase tracking-wider mb-3 m-0">Estado Financiero</h3>
                        
                        <div class="flex justify-between items-center border-b border-blue-100 dark:border-blue-800/40 pb-2">
                            <span class="text-sm text-gray-700 dark:text-gray-300">Saldo actual</span>
                            <span class="font-mono font-bold text-lg" :class="getBalanceClass(selectedCustomerForDrawer.balance)">
                                {{ formatCurrency(selectedCustomerForDrawer.balance) }}
                            </span>
                        </div>
                        <div class="flex justify-between items-center pt-1">
                            <span class="text-sm text-gray-600 dark:text-gray-400">Límite de crédito</span>
                            <span class="font-mono font-medium text-gray-800 dark:text-gray-200">
                                {{ formatCurrency(selectedCustomerForDrawer.credit_limit) }}
                            </span>
                        </div>
                        <div class="flex justify-between items-center pt-1">
                            <span class="text-sm text-gray-600 dark:text-gray-400">Crédito disponible</span>
                            <span class="font-mono font-bold text-blue-600 dark:text-blue-400">
                                {{ formatCurrency(selectedCustomerForDrawer.available_credit || 0) }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Actions Footer -->
                <div class="mt-auto pt-4 border-t dark:border-gray-700 flex flex-col gap-2 bg-white dark:bg-gray-800">
                    <Button 
                        v-if="hasPermission('customers.see_details')" 
                        label="Ver perfil completo" 
                        icon="pi pi-id-card" 
                        class="w-full" 
                        @click="router.visit(route('customers.show', selectedCustomerForDrawer.id))" 
                    />
                    <Button 
                        v-if="hasPermission('customers.edit')" 
                        label="Editar información" 
                        icon="pi pi-pencil" 
                        severity="secondary" 
                        outlined 
                        class="w-full" 
                        @click="router.visit(route('customers.edit', selectedCustomerForDrawer.id))" 
                    />
                </div>
            </div>
        </Drawer>

        <!-- Modal de Importación -->
        <ImportCustomersModal :visible="showImportModal" @update:visible="showImportModal = false" />
    </AppLayout>
</template>