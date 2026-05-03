<script setup>
import { ref, watch } from 'vue';
import { Head, router, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import { usePermissions } from '@/Composables';

// Importar Partial
import QuoteDrawerDetail from './Partials/QuoteDrawerDetail.vue';

const page = usePage();

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
        expirada: 'warn',
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
    if (target.closest('button') || target.closest('.p-button') || target.closest('.p-checkbox') || target.closest('.p-row-toggler') || target.closest('a')) {
        return;
    }

    const clickAction = page.props.auth.preferences?.quote_table_row_click_action || 'Vista lateral con algunos detalles';

    if (clickAction === 'Redirección a vista de detalles') {
        router.get(route('quotes.show', event.data.id));
    } else {
        selectedQuoteForDrawer.value = event.data;
        isDrawerVisible.value = true;
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

const subDataTablePt = {
    root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
    headerRow: { class: 'bg-gray-100 dark:bg-[#111111]' },
    headerCell: { class: 'bg-transparent text-[9px] uppercase tracking-widest text-gray-500 font-bold py-3 px-3 border-b border-gray-200 dark:border-[#3a3a3a]' },
    bodyRow: { class: 'dark:bg-[#1a1a1a] hover:bg-gray-100 dark:hover:bg-[#232323] transition-colors text-xs text-gray-600 dark:text-gray-400 group' },
    bodyCell: { class: 'py-3 px-3 border-b border-gray-100 dark:border-[#2a2a2a]' },
};

const inputPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2 !text-sm w-full' }
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' }
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
    <Head title="Cotizaciones" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                
                <!-- Header con Título -->
                <div class="mb-8">
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Catálogo de cotizaciones</h1>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.8)] animate-pulse"></span>
                        Gestión de propuestas comerciales
                    </p>
                </div>

                <!-- Barra de Herramientas de Filtros (Estilo Panel de Control) -->
                <div class="flex flex-col md:flex-row gap-4 items-center justify-between bg-gray-50 dark:bg-[#1a1a1a] p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] mb-6">
                    <IconField iconPosition="left" class="w-full md:w-1/2 lg:w-1/3">
                        <InputIcon class="pi pi-search !text-sm text-gray-400 dark:text-gray-500"></InputIcon>
                        <InputText v-model="searchTerm" placeholder="Buscar por folio o cliente..." :pt="inputPt" class="!pl-10" />
                    </IconField>
                    
                    <div class="flex items-center gap-2 w-full md:w-auto">
                        <Button v-if="hasPermission('quotes.create')" label="Nueva cotización"
                            icon="pi pi-plus" @click="router.get(route('quotes.create'))"
                            severity="warning"
                            class="!rounded-xl !text-xs !uppercase !tracking-wider flex-grow md:flex-none" />
                        
                        <Button v-if="hasPermission('quotes.export')" icon="pi pi-chevron-down"
                            @click="toggleHeaderMenu" severity="warning" class="!rounded-xl !size-9 !p-0 shrink-0" />
                        
                        <Menu ref="headerMenu" :model="splitButtonItems" :popup="true" :pt="menuPt" />
                    </div>
                </div>

                <!-- Barra de Acciones Masivas Contextual -->
                <div v-if="selectedQuotes.length > 0" class="bg-blue-50 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-900/30 rounded-2xl p-3 mb-6 flex flex-col md:flex-row justify-between items-center gap-4 transition-all duration-300">
                    <span class="font-bold text-xs uppercase tracking-widest text-blue-700 dark:text-blue-300 m-0">
                        <i class="pi pi-check-square mr-1"></i> {{ selectedQuotes.length }} seleccionadas
                    </span>
                    <div class="flex items-center gap-2 w-full md:w-auto overflow-x-auto custom-scrollbar pb-1 md:pb-0">
                        <Button v-if="hasPermission('quotes.delete')" @click="deleteSelectedQuotes" label="Eliminar"
                            icon="pi pi-trash" size="small" severity="danger" outlined 
                            class="!rounded-xl !text-xs !uppercase !tracking-wider shrink-0" />
                    </div>
                </div>

                <!-- Tabla de Cotizaciones -->
                <DataTable :value="quotes.data" v-model:selection="selectedQuotes" lazy paginator
                    :totalRecords="quotes.total" :rows="quotes.per_page" :rowsPerPageOptions="[20, 50, 100, 200]"
                    dataKey="id" @page="onPage" @sort="onSort" removableSort tableStyle="min-width: 60rem"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                    currentPageReportTemplate="Mostrando {first} a {last} de {totalRecords} cotizaciones"
                    v-model:expandedRows="expandedRows" rowHover @row-click="onRowClick" class="cursor-pointer"
                    :pt="dataTablePt">
                    
                    <Column selectionMode="multiple" headerStyle="width: 3rem"></Column>

                    <Column expander headerStyle="width: 3rem" />

                    <Column field="folio" header="Folio" sortable>
                        <template #body="{ data }">
                            <span class="font-mono font-bold dark:text-gray-300">{{ data.folio }}</span>
                            <Tag v-if="data.versions && data.versions.length > 0"
                                :value="`+${data.versions.length} ${data.versions.length > 1 ? 'versiones' : 'versión'}`"
                                class="ml-2" severity="secondary" :pt="tagPt" />
                        </template>
                    </Column>

                    <Column field="customer.name" header="Cliente" sortable>
                        <template #body="{ data }">
                            <div v-if="data.customer">
                                <Link :href="route('customers.show', data.customer.id)"
                                    class="font-medium text-gray-900 dark:text-gray-100 hover:text-primary-500 transition-colors m-0 block w-max" @click.stop>
                                    {{ data.customer.name }}
                                </Link>
                                <div v-if="data.recipient_name && data.recipient_name !== data.customer.name"
                                    class="text-[10px] text-gray-500 uppercase tracking-widest mt-0.5">
                                    <i class="pi pi-user !text-[9px]"></i> {{ data.recipient_name }}
                                </div>
                            </div>
                            <div v-else>
                                <span class="font-medium text-gray-900 dark:text-gray-100 m-0">{{ data.recipient_name || 'N/A' }}</span>
                            </div>
                        </template>
                    </Column>

                    <Column field="expiry_date" header="Vencimiento" sortable>
                        <template #body="{ data }"> 
                            <span class="text-gray-600 dark:text-gray-400">{{ formatDate(data.expiry_date) }}</span> 
                        </template>
                    </Column>
                    
                    <Column field="status" header="Estatus" sortable>
                        <template #body="{ data }">
                            <Tag :value="data.status.replace('_', ' ')" :severity="getStatusSeverity(data.status)" class="capitalize" :pt="tagPt" />
                        </template>
                    </Column>
                    
                    <Column field="total_amount" header="Total" sortable>
                        <template #body="{ data }">
                            <span class="font-light tracking-tight text-lg dark:text-white">{{ formatCurrency(data.total_amount) }}</span>
                        </template>
                    </Column>
                    
                    <Column headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }"> 
                            <Button @click="toggleMenu($event, data)" icon="pi pi-ellipsis-v" text rounded class="!w-8 !h-8 !text-gray-400 hover:!bg-gray-200 dark:hover:!bg-[#2a2a2a] !transition-colors" /> 
                        </template>
                    </Column>
                    
                    <template #empty>
                        <div class="flex flex-col items-center justify-center text-center py-10">
                            <i class="pi pi-file !text-3xl text-gray-400 mb-3"></i>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin resultados</p>
                            <p class="text-gray-400 mt-1">No hay cotizaciones que coincidan con la búsqueda actual.</p>
                        </div>
                    </template>

                    <!-- Template de expansión (la sub-tabla) -->
                    <template #expansion="{ data }">
                        <div v-if="data.versions && data.versions.length > 0" class="p-4 mx-4 my-2 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#2a2a2a]">
                            <h4 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-3">Historial de versiones: {{ data.folio }}</h4>
                            <DataTable :value="data.versions" @row-click.stop :pt="subDataTablePt">
                                <Column field="folio" header="Folio" style="width: 10rem">
                                    <template #body="{ data: version }">
                                        <span class="font-mono text-xs">{{ version.folio }}</span>
                                    </template>
                                </Column>
                                <Column field="status" header="Estatus" style="width: 10rem">
                                    <template #body="{ data: version }">
                                        <Tag :value="version.status.replace('_', ' ')" :severity="getStatusSeverity(version.status)" class="capitalize" :pt="tagPt" />
                                    </template>
                                </Column>
                                <Column field="total_amount" header="Total" style="width: 10rem">
                                    <template #body="{ data: version }">
                                        <span class="font-light tracking-tight text-sm dark:text-white">{{ formatCurrency(version.total_amount) }}</span>
                                    </template>
                                </Column>
                                <Column headerStyle="width: 5rem; text-align: center">
                                    <template #body="{ data: version }">
                                        <Button @click.stop="router.get(route('quotes.show', version.id))" icon="pi pi-eye" text rounded class="!w-8 !h-8 !text-gray-400 hover:!bg-gray-200 dark:hover:!bg-[#232323] !transition-colors" v-tooltip.top="'Ver detalles'" />
                                    </template>
                                </Column>
                            </DataTable>
                        </div>
                        <!-- Mensaje si se expande una fila sin versiones -->
                        <div v-else class="p-4 mx-4 my-2 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#2a2a2a] text-center">
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Única versión</p>
                            <p class="text-xs text-gray-400 mt-1">Esta cotización no tiene variaciones registradas.</p>
                        </div>
                    </template>

                </DataTable>

                <Menu ref="menu" :model="menuItems" :popup="true" :pt="menuPt" />
            </div>
        </div>

        <!-- Drawer de Detalles de Cotización Aislado -->
        <Drawer v-model:visible="isDrawerVisible" position="right" class="w-full md:!w-[30rem]" :pt="drawerPt">
            <template #header>
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                        <i class="pi pi-file text-blue-500 !text-sm"></i>
                    </div>
                    <span class="text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0">Detalles rápidos</span>
                </div>
            </template>
            
            <QuoteDrawerDetail 
                v-if="selectedQuoteForDrawer"
                :quote="selectedQuoteForDrawer"
                :can-see-details="hasPermission('quotes.see_details')"
                :can-edit="hasPermission('quotes.edit')"
                @go-to-details="router.visit(route('quotes.show', selectedQuoteForDrawer.id))"
                @go-to-edit="router.visit(route('quotes.edit', selectedQuoteForDrawer.id))"
            />
        </Drawer>
    </AppLayout>
</template>