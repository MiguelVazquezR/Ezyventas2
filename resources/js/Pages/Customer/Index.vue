<script setup>
import { ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";

const props = defineProps({
    customers: Object,
    filters: Object,
});

const confirm = useConfirm();

// --- Estado y Lógica ---
const selectedCustomers = ref([]);
const searchTerm = ref(props.filters.search || '');

const splitButtonItems = ref([
    { label: 'Importar Clientes', icon: 'pi pi-upload' },
    { label: 'Exportar Clientes', icon: 'pi pi-download' },
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
                onSuccess: () => selectedCustomers.value = [], // Limpiar la selección
                preserveScroll: true,
            });
        }
    });
};

const menuItems = ref([
    { label: 'Ver Perfil', icon: 'pi pi-eye', command: () => router.get(route('customers.show', selectedCustomerForMenu.value.id)) },
    { label: 'Editar Cliente', icon: 'pi pi-pencil', command: () => router.get(route('customers.edit', selectedCustomerForMenu.value.id)) },
    { label: 'Registrar Venta', icon: 'pi pi-shopping-cart' },
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: deleteSingleCustomer },
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
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};
</script>

<template>
    <Head title="Clientes" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 bg-gray-100 dark:bg-gray-900 min-h-full">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 md:p-6">
                <!-- Header -->
                <div class="mb-6 flex flex-col md:flex-row justify-between items-center gap-4">
                    <IconField iconPosition="left" class="w-full md:w-1/3">
                        <InputIcon class="pi pi-search"></InputIcon>
                        <InputText v-model="searchTerm" placeholder="Buscar por nombre, empresa, email..." class="w-full" />
                    </IconField>
                    <div class="flex items-center gap-2">
                        <SplitButton label="Nuevo Cliente" icon="pi pi-plus" @click="router.get(route('customers.create'))" :model="splitButtonItems" severity="warning"></SplitButton>
                    </div>
                </div>

                <!-- Barra de Acciones Masivas -->
                <div v-if="selectedCustomers.length > 0" class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg p-2 mb-4 flex justify-between items-center">
                    <span class="font-semibold text-sm text-blue-800 dark:text-blue-200">{{ selectedCustomers.length }} cliente(s) seleccionado(s)</span>
                    <Button @click="deleteSelectedCustomers" label="Eliminar" icon="pi pi-trash" size="small" severity="danger" outlined />
                </div>

                <!-- Tabla de Clientes -->
                <DataTable :value="customers.data" v-model:selection="selectedCustomers" lazy paginator
                    :totalRecords="customers.total" :rows="customers.per_page"
                    :rowsPerPageOptions="[20, 50, 100, 200]" dataKey="id" @page="onPage" @sort="onSort"
                    removableSort tableStyle="min-width: 60rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} clientes">
                    <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>
                    <Column field="name" header="Nombre" sortable>
                        <template #body="{ data }">
                            <div>
                                <p class="font-semibold">{{ data.name }}</p>
                                <p v-if="data.company_name" class="text-xs text-gray-500">{{ data.company_name }}</p>
                            </div>
                        </template>
                    </Column>
                    <Column field="phone" header="Contacto" sortable>
                         <template #body="{ data }">
                            <div>
                                <p v-if="data.phone"><i class="pi pi-phone text-xs mr-2"></i>{{ data.phone }}</p>
                                <p v-if="data.email"><i class="pi pi-envelope text-xs mr-2"></i>{{ data.email }}</p>
                            </div>
                        </template>
                    </Column>
                    <Column field="balance" header="Saldo" sortable>
                        <template #body="{ data }">
                            <span :class="getBalanceClass(data.balance)" class="font-mono font-semibold">
                                {{ formatCurrency(data.balance) }}
                            </span>
                        </template>
                    </Column>
                     <Column field="credit_limit" header="Límite de Crédito" sortable>
                        <template #body="{ data }">
                           {{ formatCurrency(data.credit_limit) }}
                        </template>
                    </Column>
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }"> <Button @click="toggleMenu($event, data)" icon="pi pi-ellipsis-v" text rounded severity="secondary" /> </template>
                    </Column>
                </DataTable>
                
                <Menu ref="menu" :model="menuItems" :popup="true" />
            </div>
        </div>
    </AppLayout>
</template>