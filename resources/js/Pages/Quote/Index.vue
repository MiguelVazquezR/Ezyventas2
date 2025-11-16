<script setup>
import { ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import { usePermissions } from '@/Composables';

const props = defineProps({
    quotes: Object,
    filters: Object,
});

const confirm = useConfirm();
const { hasPermission } = usePermissions();

// --- Estado y Lógica ---
const selectedQuotes = ref([]);
const searchTerm = ref(props.filters.search || '');
const headerMenu = ref();
const menu = ref();
const selectedQuoteForMenu = ref(null);
const expandedRows = ref({}); // De la corrección anterior

const toggleHeaderMenu = (event) => {
    headerMenu.value.toggle(event);
};
const splitButtonItems = ref([
    // { label: 'Importar Cotizaciones', icon: 'pi pi-upload', command: () => showImportModal.value = true },
    { label: 'Exportar cotizaciones', icon: 'pi pi-download', command: () => window.location.href = route('import-export.quotes.export') },
]);

// --- Lógica de Acciones ---
const deleteSingleQuote = () => {
    if (!selectedQuoteForMenu.value) return;
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar la cotización #${selectedQuoteForMenu.value.folio}?`,
        header: 'Confirmar Eliminación',
        icon: 'pi pi-info-circle',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(route('quotes.destroy', selectedQuoteForMenu.value.id), {
                preserveScroll: true,
                onSuccess: () => {
                    selectedQuotes.value = selectedQuotes.value.filter(q => q.id !== selectedQuoteForMenu.value.id);
                }
            });
        }
    });
};

const deleteSelectedQuotes = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar las ${selectedQuotes.value.length} cotizaciones seleccionadas?`,
        header: 'Eliminación Masiva',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        accept: () => {
            const idsToDelete = selectedQuotes.value.map(q => q.id);
            router.post(route('quotes.batchDestroy'), { ids: idsToDelete }, {
                onSuccess: () => selectedQuotes.value = [],
            });
        }
    });
};

// --- INICIO: NUEVAS ACCIONES ---
/**
 * Llama al endpoint para convertir una cotización 'autorizada' en una 'transacción'.
 */
const convertToSale = () => {
    if (!selectedQuoteForMenu.value) return;
    confirm.require({
        message: `Se creará una nueva venta (Transacción) con los datos de esta cotización. El estatus cambiará a "Venta generada". ¿Deseas continuar?`,
        header: 'Confirmar conversión a venta',
        icon: 'pi pi-dollar',
        acceptClass: 'p-button-success',
        accept: () => {
            router.post(route('quotes.convertToSale', selectedQuoteForMenu.value.id), {}, {
                preserveScroll: true,
                onSuccess: () => selectedQuoteForMenu.value = null,
            });
        }
    });
};

/**
 * Llama al endpoint 'updateStatus' para cambiar el estatus a 'cancelada'.
 */
const cancelSale = () => {
    if (!selectedQuoteForMenu.value) return;
    confirm.require({
        message: `Esta acción cancelará la venta asociada (marcando la transacción como cancelada/reembolsada) y devolverá el stock al inventario. ¿Estás seguro?`,
        header: 'Confirmar cancelación de venta',
        icon: 'pi pi-times-circle',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.patch(route('quotes.updateStatus', selectedQuoteForMenu.value.id), {
                status: 'cancelada'
            }, {
                preserveScroll: true,
                onSuccess: () => selectedQuoteForMenu.value = null,
            });
        }
    });
};

// El menú ahora se genera dinámicamente
const menuItems = ref([]);

/**
 * Actualiza dinámicamente los items del menú de acciones
 * basándose en el estatus de la cotización seleccionada.
 */
const toggleMenu = (event, data) => {
    selectedQuoteForMenu.value = data;
    const quote = data;
    const items = [];

    // Acción: Ver
    items.push({ 
        label: 'Ver', 
        icon: 'pi pi-eye', 
        command: () => router.get(route('quotes.show', quote.id)), 
        visible: hasPermission('quotes.see_details') 
    });

    // Acción: Editar
    const canEdit = ['borrador', 'enviado', 'autorizada'].includes(quote.status);
    if (canEdit) {
        items.push({ 
            label: 'Editar cotización', 
            icon: 'pi pi-pencil', 
            command: () => router.get(route('quotes.edit', quote.id)), 
            visible: hasPermission('quotes.edit') 
        });
    }

    // Acción: Convertir a Venta
    const canConvertToSale = (quote.status === 'autorizada' && !quote.transaction_id);
    if (canConvertToSale) {
        items.push({ 
            label: 'Convertir a venta', 
            icon: 'pi pi-dollar',
            command: convertToSale,
            visible: hasPermission('quotes.create_sale') 
        });
    }

    // Acción: Cancelar Venta
    const canCancel = (quote.status === 'venta_generada');
    if (canCancel) {
        items.push({
            label: 'Cancelar venta',
            icon: 'pi pi-times-circle',
            class: 'text-orange-500', // Un color distintivo
            command: cancelSale,
            visible: hasPermission('quotes.change_status') // Asumiendo que este permiso controla la cancelación
        });
    }

    items.push({ separator: true });

    // Acción: Eliminar
    // No permitir eliminar si ya generó una venta (debe cancelarse primero)
    const canDelete = (quote.status !== 'venta_generada');
    if (canDelete) {
        items.push({ 
            label: 'Eliminar', 
            icon: 'pi pi-trash', 
            class: 'text-red-500', 
            command: deleteSingleQuote, 
            visible: hasPermission('quotes.delete') 
        });
    }
    
    menuItems.value = items;
    menu.value.toggle(event);
};

// --- Lógica de la Tabla ---
const fetchData = (options = {}) => {
    const queryParams = {
        page: options.page || 1,
        rows: options.rows || props.quotes.per_page,
        sortField: options.sortField || props.filters.sortField,
        sortOrder: options.sortOrder === 1 ? 'asc' : 'desc',
        search: searchTerm.value,
    };
    router.get(route('quotes.index'), queryParams, { preserveState: true, replace: true });
};

const onPage = (event) => fetchData({ page: event.page + 1, rows: event.rows });
const onSort = (event) => fetchData({ sortField: event.sortField, sortOrder: event.sortOrder });
watch(searchTerm, () => fetchData());

const getStatusSeverity = (status) => {
    // Añadido el nuevo estatus 'cancelada'
    const map = { 
        borrador: 'secondary', 
        enviado: 'info', 
        autorizada: 'success', 
        rechazada: 'danger', 
        venta_generada: 'success', 
        expirada: 'warning',
        cancelada: 'danger' // O 'warning', como prefieras
    };
    return map[status] || 'secondary';
};

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    const userTimezoneOffset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() + userTimezoneOffset).toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' });
};

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
</script>

<template>

    <Head title="Cotizaciones" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 bg-gray-100 dark:bg-gray-900 min-h-full">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 md:p-6">
                <!-- Header -->
                <div class="mb-6 flex flex-col md:flex-row justify-between items-center gap-4">
                    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Cotizaciones</h1>
                    <div class="flex items-center gap-2">
                        <IconField iconPosition="left" class="w-full md:w-auto">
                            <InputIcon class="pi pi-search"></InputIcon>
                            <InputText v-model="searchTerm" placeholder="Buscar por folio o cliente..."
                                class="w-full" />
                        </IconField>
                        <ButtonGroup>
                            <Button v-if="hasPermission('quotes.create')" label="Nueva cotización" icon="pi pi-plus"
                                @click="router.get(route('quotes.create'))" severity="warning" />
                            <Button v-if="hasPermission('quotes.export')" icon="pi pi-chevron-down"
                                @click="toggleHeaderMenu" severity="warning" />
                        </ButtonGroup>
                        <Menu ref="headerMenu" :model="splitButtonItems" :popup="true" />
                    </div>
                </div>

                <!-- Barra de Acciones Masivas -->
                <div v-if="selectedQuotes.length > 0"
                    class="bg-gray-50 dark:bg-gray-900/20 border border-gray-200 dark:border-gray-700 rounded-lg p-2 mb-4 flex justify-between items-center">
                    <span class="font-semibold text-sm text-[#373737] dark:text-gray-200">{{ selectedQuotes.length }}
                        cotización(es) seleccionada(s)</span>
                    <Button v-if="hasPermission('quotes.delete')" @click="deleteSelectedQuotes" label="Eliminar" icon="pi pi-trash" size="small"
                        severity="danger" outlined />
                </div>

                <!-- Tabla de Cotizaciones (Sin cambios en el template, la magia está en el script) -->
                <DataTable :value="quotes.data" v-model:selection="selectedQuotes" lazy paginator
                    :totalRecords="quotes.total" :rows="quotes.per_page" :rowsPerPageOptions="[20, 50, 100, 200]"
                    dataKey="id" @page="onPage" @sort="onSort" removableSort tableStyle="min-width: 60rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} cotizaciones"
                    v-model:expandedRows="expandedRows" 
                    >
                    <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>
                    
                    <Column expander headerStyle="width: 3rem" />
                    
                    <Column field="folio" header="Folio" sortable>
                        <template #body="{ data }">
                            {{ data.folio }}
                            <Tag v-if="data.versions && data.versions.length > 0" :value="`+${data.versions.length} ${data.versions.length > 1 ? 'versiones' : 'versión'}`" class="ml-2" size="small" severity="contrast" />
                        </template>
                    </Column>
                    <Column field="customer.name" header="Cliente" sortable></Column>
                    <Column field="expiry_date" header="Fecha de expiración" sortable>
                        <template #body="{ data }"> {{ formatDate(data.expiry_date) }} </template>
                    </Column>
                    <Column field="status" header="Estatus" sortable>
                        <template #body="{ data }">
                            <Tag :value="data.status.replace('_', ' ')" :severity="getStatusSeverity(data.status)"
                                class="capitalize" />
                        </template>
                    </Column>
                    <Column field="total_amount" header="Total" sortable>
                        <template #body="{ data }"> {{ formatCurrency(data.total_amount) }} </template>
                    </Column>
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }"> <Button @click="toggleMenu($event, data)" icon="pi pi-ellipsis-v"
                                text rounded severity="secondary" /> </template>
                    </Column>

                    <!-- Template de expansión (la sub-tabla) -->
                    <template #expansion="{ data }">
                        <div v-if="data.versions && data.versions.length > 0" class="p-3 sm:p-4 bg-gray-50 dark:bg-gray-900/50">
                            <h4 class="font-bold text-sm mb-2 text-gray-700 dark:text-gray-300">Versiones de {{ data.folio }}</h4>
                            <DataTable :value="data.versions" class="p-datatable-sm" size="small">
                                <Column field="folio" header="Versión Folio" style="width: 10rem"></Column>
                                <Column field="customer.name" header="Cliente"></Column>
                                <Column field="status" header="Estatus" style="width: 10rem">
                                    <template #body="{ data: version }">
                                        <Tag :value="version.status.replace('_', ' ')" :severity="getStatusSeverity(version.status)" class="capitalize" />
                                    </template>
                                </Column>
                                <Column field="total_amount" header="Total" style="width: 10rem" class="text-right">
                                    <template #body="{ data: version }">
                                        {{ formatCurrency(version.total_amount) }}
                                    </template>
                                </Column>
                                <Column header="Acción" style="width: 5rem" align="center">
                                    <template #body="{ data: version }">
                                        <Button @click="router.get(route('quotes.show', version.id))" icon="pi pi-eye" text rounded severity="secondary" v-tooltip.top="'Ver detalles'" />
                                    </template>
                                </Column>
                            </DataTable>
                        </div>
                        <!-- Mensaje si se expande una fila sin versiones -->
                        <div v-else class="p-3 sm:p-4 text-center text-gray-500 dark:text-gray-400">
                            Esta cotización no tiene otras versiones.
                        </div>
                    </template>

                </DataTable>

                <!-- El menú ahora se alimenta de 'menuItems' que se genera dinámicamente -->
                <Menu ref="menu" :model="menuItems" :popup="true" />
            </div>
        </div>
    </AppLayout>
</template>