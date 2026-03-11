<script setup>
import { ref, watch } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
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
const expandedRows = ref({});

// --- Estado para el Drawer ---
const isDrawerVisible = ref(false);
const selectedQuoteForDrawer = ref(null);

const toggleHeaderMenu = (event) => {
    headerMenu.value.toggle(event);
};
const splitButtonItems = ref([
    { label: 'Exportar Cotizaciones', icon: 'pi pi-download', command: () => window.location.href = route('import-export.quotes.export') },
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
                    if (selectedQuoteForDrawer.value?.id === selectedQuoteForMenu.value.id) {
                        isDrawerVisible.value = false;
                    }
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
                onSuccess: () => {
                    selectedQuotes.value = [];
                    isDrawerVisible.value = false;
                },
                preserveScroll: true,
            });
        }
    });
};

// --- Nuevas Acciones ---
const convertToSale = () => {
    if (!selectedQuoteForMenu.value) return;
    confirm.require({
        message: `Se creará una nueva venta (Transacción) con los datos de esta cotización. El estatus cambiará a "Venta Generada". ¿Deseas continuar?`,
        header: 'Confirmar Conversión a Venta',
        icon: 'pi pi-dollar',
        acceptClass: 'p-button-success',
        accept: () => {
            router.post(route('quotes.convertToSale', selectedQuoteForMenu.value.id), {}, {
                preserveScroll: true,
                onSuccess: () => {
                    selectedQuoteForMenu.value = null;
                    isDrawerVisible.value = false;
                },
            });
        }
    });
};

const cancelSale = () => {
    if (!selectedQuoteForMenu.value) return;
    confirm.require({
        message: `Esta acción cancelará la venta asociada (marcando la transacción como cancelada/reembolsada) y devolverá el stock al inventario. ¿Estás seguro?`,
        header: 'Confirmar Cancelación de Venta',
        icon: 'pi pi-times-circle',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.patch(route('quotes.updateStatus', selectedQuoteForMenu.value.id), {
                status: 'cancelada'
            }, {
                preserveScroll: true,
                onSuccess: () => {
                    selectedQuoteForMenu.value = null;
                    isDrawerVisible.value = false;
                },
            });
        }
    });
};


// El menú ahora se genera dinámicamente
const menuItems = ref([]);

const toggleMenu = (event, data) => {
    selectedQuoteForMenu.value = data;
    const quote = data;
    const items = [];

    // Acción: Ver
    items.push({
        label: 'Ver detalles',
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
            class: 'text-orange-500',
            command: cancelSale,
            visible: hasPermission('quotes.change_status')
        });
    }

    items.push({ separator: true });

    // Acción: Eliminar
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
    const map = {
        borrador: 'secondary',
        enviado: 'info',
        autorizada: 'success',
        rechazada: 'danger',
        venta_generada: 'success',
        expirada: 'warning',
        cancelada: 'danger'
    };
    return map[status] || 'secondary';
};

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    const userTimezoneOffset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() + userTimezoneOffset).toLocaleDateString('es-MX', { year: 'numeric', month: 'short', day: 'numeric' });
};

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);

const onRowClick = (event) => {
    const target = event.originalEvent.target;
    // Evitar abrir el drawer si se hace clic en botones, checkboxes o el icono para expandir filas
    if (target.closest('button') || target.closest('.p-button') || target.closest('.p-checkbox') || target.closest('.p-row-toggler')) {
        return;
    }
    
    selectedQuoteForDrawer.value = event.data;
    isDrawerVisible.value = true;
};
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
                    <Button v-if="hasPermission('quotes.delete')" @click="deleteSelectedQuotes" label="Eliminar"
                        icon="pi pi-trash" size="small" severity="danger" outlined />
                </div>

                <!-- Tabla de Cotizaciones -->
                <DataTable :value="quotes.data" v-model:selection="selectedQuotes" lazy paginator
                    :totalRecords="quotes.total" :rows="quotes.per_page" :rowsPerPageOptions="[20, 50, 100, 200]"
                    dataKey="id" @page="onPage" @sort="onSort" removableSort tableStyle="min-width: 60rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} cotizaciones"
                    v-model:expandedRows="expandedRows" rowHover @row-click="onRowClick" class="cursor-pointer">
                    
                    <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>

                    <Column expander headerStyle="width: 3rem" />

                    <Column field="folio" header="Folio" sortable>
                        <template #body="{ data }">
                            <span class="font-semibold">{{ data.folio }}</span>
                            <Tag v-if="data.versions && data.versions.length > 0"
                                :value="`+${data.versions.length} ${data.versions.length > 1 ? 'versiones' : 'versión'}`"
                                class="ml-2" size="small" severity="contrast" />
                        </template>
                    </Column>

                    <!-- COLUMNA CLIENTE ACTUALIZADA -->
                    <Column field="customer.name" header="Cliente" sortable>
                        <template #body="{ data }">
                            <div v-if="data.customer">
                                <!-- Nombre del cliente relacionado (Clickable) -->
                                <Link :href="route('customers.show', data.customer.id)"
                                    class="font-medium text-primary dark:text-orange-300 hover:underline" @click.stop>
                                    {{ data.customer.name }}
                                </Link>
                                <!-- Nombre del destinatario si es diferente -->
                                <div v-if="data.recipient_name && data.recipient_name !== data.customer.name"
                                    class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    Dirigido a: {{ data.recipient_name }}
                                </div>
                            </div>
                            <div v-else>
                                <!-- Solo recipient_name si no hay cliente relacionado -->
                                {{ data.recipient_name || 'N/A' }}
                            </div>
                        </template>
                    </Column>

                    <Column field="expiry_date" header="Vencimiento" sortable>
                        <template #body="{ data }"> {{ formatDate(data.expiry_date) }} </template>
                    </Column>
                    <Column field="status" header="Estatus" sortable>
                        <template #body="{ data }">
                            <Tag :value="data.status.replace('_', ' ')" :severity="getStatusSeverity(data.status)"
                                class="capitalize" />
                        </template>
                    </Column>
                    <Column field="total_amount" header="Total" sortable>
                        <template #body="{ data }">
                            <span class="font-mono font-medium">{{ formatCurrency(data.total_amount) }}</span>
                        </template>
                    </Column>
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }"> 
                            <Button @click="toggleMenu($event, data)" icon="pi pi-ellipsis-v" text rounded severity="secondary" /> 
                        </template>
                    </Column>
                    <template #empty>
                        <div class="text-center py-4">No hay cotizaciones registradas.</div>
                    </template>

                    <!-- Template de expansión (la sub-tabla) -->
                    <template #expansion="{ data }">
                        <div v-if="data.versions && data.versions.length > 0"
                            class="p-3 sm:p-4 bg-gray-50 dark:bg-gray-900/50">
                            <h4 class="font-bold text-sm mb-2 text-gray-700 dark:text-gray-300">Versiones de {{
                                data.folio }}</h4>
                            <DataTable :value="data.versions" class="p-datatable-sm" size="small" @row-click.stop>
                                <Column field="folio" header="Versión Folio" style="width: 10rem"></Column>
                                <Column field="customer.name" header="Cliente"></Column>
                                <Column field="status" header="Estatus" style="width: 10rem">
                                    <template #body="{ data: version }">
                                        <Tag :value="version.status.replace('_', ' ')"
                                            :severity="getStatusSeverity(version.status)" class="capitalize" />
                                    </template>
                                </Column>
                                <Column field="total_amount" header="Total" style="width: 10rem" class="text-right">
                                    <template #body="{ data: version }">
                                        <span class="font-mono">{{ formatCurrency(version.total_amount) }}</span>
                                    </template>
                                </Column>
                                <Column header="Acción" style="width: 5rem" align="center">
                                    <template #body="{ data: version }">
                                        <Button @click.stop="router.get(route('quotes.show', version.id))" icon="pi pi-eye"
                                            text rounded severity="secondary" v-tooltip.top="'Ver detalles'" />
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

                <Menu ref="menu" :model="menuItems" :popup="true" />
            </div>
        </div>

        <!-- Drawer de Detalles de Cotización -->
        <Drawer v-model:visible="isDrawerVisible" position="right" class="w-full md:!w-[30rem]">
            <template #header>
                <div class="flex items-center gap-2">
                    <i class="pi pi-file text-xl text-gray-600 dark:text-gray-300"></i>
                    <span class="font-bold text-xl text-gray-800 dark:text-gray-100">Detalles de Cotización</span>
                </div>
            </template>
            
            <div v-if="selectedQuoteForDrawer" class="flex flex-col h-full pt-4">
                <div class="flex-grow space-y-6 overflow-y-auto pr-2 pb-6">
                    
                    <!-- Header Información Principal -->
                    <div class="flex justify-between items-start border-b border-gray-200 dark:border-gray-700 pb-4">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100 m-0">{{ selectedQuoteForDrawer.folio }}</h2>
                            <p class="text-sm text-gray-500 mt-1 m-0">Creada: {{ formatDate(selectedQuoteForDrawer.created_at) }}</p>
                        </div>
                        <Tag 
                            :value="selectedQuoteForDrawer.status.replace('_', ' ')" 
                            :severity="getStatusSeverity(selectedQuoteForDrawer.status)" 
                            class="capitalize !text-sm" 
                        />
                    </div>

                    <!-- Datos del Cliente -->
                    <div class="space-y-3 bg-gray-50 dark:bg-gray-800/60 p-4 rounded-xl border border-gray-100 dark:border-gray-700">
                        <h3 class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider m-0">Información del Cliente</h3>
                        
                        <div class="flex items-start gap-3">
                            <div class="mt-1 bg-white dark:bg-gray-700 p-1.5 rounded-md shadow-sm border border-gray-100 dark:border-gray-600">
                                <i class="pi pi-user text-gray-500 dark:text-gray-400"></i>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-gray-800 dark:text-gray-200 m-0">
                                    {{ selectedQuoteForDrawer.customer ? selectedQuoteForDrawer.customer.name : (selectedQuoteForDrawer.recipient_name || 'No especificado') }}
                                </p>
                                <span class="text-xs text-gray-500">Cliente / Destinatario</span>
                            </div>
                        </div>

                        <div v-if="selectedQuoteForDrawer.expiry_date" class="flex items-start gap-3 mt-2">
                            <div class="mt-1 bg-white dark:bg-gray-700 p-1.5 rounded-md shadow-sm border border-gray-100 dark:border-gray-600">
                                <i class="pi pi-calendar text-gray-500 dark:text-gray-400"></i>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200 m-0">
                                    {{ formatDate(selectedQuoteForDrawer.expiry_date) }}
                                </p>
                                <span class="text-xs text-gray-500">Fecha de vencimiento</span>
                            </div>
                        </div>
                    </div>

                    <!-- Desglose Financiero -->
                    <div class="space-y-3 bg-blue-50 dark:bg-blue-900/20 p-4 rounded-xl border border-blue-100 dark:border-blue-800/40">
                        <h3 class="text-xs font-bold text-blue-800 dark:text-blue-300 uppercase tracking-wider mb-3 m-0">Desglose Financiero</h3>
                        
                        <div class="flex justify-between items-center text-sm text-gray-600 dark:text-gray-300">
                            <span>Subtotal</span>
                            <span class="font-mono">{{ formatCurrency(selectedQuoteForDrawer.subtotal) }}</span>
                        </div>
                        
                        <div v-if="selectedQuoteForDrawer.total_discount > 0" class="flex justify-between items-center text-sm text-red-500 dark:text-red-400">
                            <span>Descuento</span>
                            <span class="font-mono">- {{ formatCurrency(selectedQuoteForDrawer.total_discount) }}</span>
                        </div>

                        <div v-if="selectedQuoteForDrawer.total_tax > 0" class="flex justify-between items-center text-sm text-gray-600 dark:text-gray-300">
                            <span>Impuestos</span>
                            <span class="font-mono">+ {{ formatCurrency(selectedQuoteForDrawer.total_tax) }}</span>
                        </div>

                        <div v-if="selectedQuoteForDrawer.shipping_cost > 0" class="flex justify-between items-center text-sm text-gray-600 dark:text-gray-300">
                            <span>Envío / Visita</span>
                            <span class="font-mono">+ {{ formatCurrency(selectedQuoteForDrawer.shipping_cost) }}</span>
                        </div>

                        <div class="flex justify-between items-center border-t border-blue-200 dark:border-blue-800/60 pt-3 mt-2">
                            <span class="font-bold text-gray-800 dark:text-gray-200">Total</span>
                            <span class="font-mono font-bold text-xl text-blue-700 dark:text-blue-400">
                                {{ formatCurrency(selectedQuoteForDrawer.total_amount) }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Acciones Rápidas (Footer) -->
                <div class="mt-auto pt-4 border-t dark:border-gray-700 flex flex-col gap-2 bg-white dark:bg-gray-800">
                    <Button 
                        v-if="hasPermission('quotes.see_details')" 
                        label="Ver documento completo" 
                        icon="pi pi-file" 
                        class="w-full" 
                        @click="router.visit(route('quotes.show', selectedQuoteForDrawer.id))" 
                    />
                    <Button 
                        v-if="hasPermission('quotes.edit') && ['borrador', 'enviado', 'autorizada'].includes(selectedQuoteForDrawer.status)" 
                        label="Editar cotización" 
                        icon="pi pi-pencil" 
                        severity="secondary" 
                        outlined 
                        class="w-full" 
                        @click="router.visit(route('quotes.edit', selectedQuoteForDrawer.id))" 
                    />
                </div>
            </div>
        </Drawer>
    </AppLayout>
</template>