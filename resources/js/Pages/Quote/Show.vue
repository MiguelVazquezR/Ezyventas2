<script setup>
import { ref, computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from 'primevue/useconfirm';
import DiffViewer from '@/Components/DiffViewer.vue';
import { usePermissions } from '@/Composables';

const props = defineProps({
    quote: Object,
    activities: Array,
});

const confirm = useConfirm();

// composables
const { hasPermission } = usePermissions();

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Cotizaciones', url: route('quotes.index') },
    { label: `Cotización #${props.quote.folio}` }
]);

// --- Lógica del Flujo de Estatus ---
const steps = ref([
    { label: 'Borrador', value: 'borrador', icon: 'pi pi-file-edit' },
    { label: 'Enviado', value: 'enviado', icon: 'pi pi-send' },
    { label: 'Autorizada', value: 'autorizada', icon: 'pi pi-check-circle' },
    { label: 'Venta Generada', value: 'venta_generada', icon: 'pi pi-dollar' },
]);

const activeIndex = computed(() => {
    const index = steps.value.findIndex(step => step.value === props.quote.status);
    return index >= 0 ? index + 1 : 0;
});

const isTerminalStatus = computed(() => ['rechazada', 'venta_generada', 'expirada'].includes(props.quote.status));

const changeStatus = (newStatusValue, newIndex) => {
    if (newIndex < activeIndex.value || isTerminalStatus.value) return;
    const newStatusLabel = steps.value.find(s => s.value === newStatusValue)?.label || newStatusValue;
    confirm.require({
        message: `¿Estás seguro de que quieres cambiar el estatus a "${newStatusLabel}"?`,
        header: 'Confirmar Cambio de Estatus',
        icon: 'pi pi-sync',
        accept: () => {
            router.patch(route('quotes.updateStatus', props.quote.id), { status: newStatusValue }, { preserveScroll: true });
        }
    });
};

// --- Lógica de Acciones ---
const createNewVersion = () => {
    confirm.require({
        message: 'Se creará una nueva versión de esta cotización en estado "Borrador" para que puedas editarla. ¿Deseas continuar?',
        header: 'Crear Nueva Versión',
        icon: 'pi pi-copy',
        accept: () => {
            router.post(route('quotes.newVersion', props.quote.id));
        }
    });
};

const deleteQuote = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar la cotización #${props.quote.folio}? Esta acción no se puede deshacer.`,
        header: 'Confirmar Eliminación',
        accept: () => router.delete(route('quotes.destroy', props.quote.id))
    });
};

const actionItems = ref([
    { label: 'Editar', icon: 'pi pi-pencil', command: () => router.get(route('quotes.edit', props.quote.id)), disabled: isTerminalStatus.value, visible: hasPermission('quotes.create') },
    { label: 'Crear nueva versión', icon: 'pi pi-copy', command: createNewVersion, visible: hasPermission('quotes.create') },
    { label: 'Ver PDF / Imprimir', icon: 'pi pi-print', command: () => window.open(route('quotes.print', props.quote.id), '_blank') },
    { label: 'Convertir a venta', icon: 'pi pi-dollar', disabled: props.quote.status !== 'autorizada', visible: hasPermission('quotes.create_sale') },
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: deleteQuote, visible: hasPermission('quotes.delete') },
]);

// --- Helpers de Formato y Lógica de Vista ---
const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' });
};
const getStatusSeverity = (status) => {
    const map = { borrador: 'secondary', enviado: 'info', autorizada: 'success', rechazada: 'danger', venta_generada: 'primary', expirada: 'warning' };
    return map[status] || 'secondary';
};

const allVersions = computed(() => {
    const parent = props.quote.parent ? [props.quote.parent, ...props.quote.parent.versions.filter(v => v.id !== props.quote.id)] : [];
    const selfAndVersions = [props.quote, ...props.quote.versions];
    const combined = [...parent, ...selfAndVersions];
    return [...new Map(combined.map(item => [item.id, item])).values()].sort((a, b) => a.version_number - b.version_number);
});
</script>

<template>

    <Head :title="`Cotización #${quote.folio}`" />
    <AppLayout>
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mt-4 mb-6">
            <div>
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">Cotización #{{ quote.folio }}</h1>
                <p class="text-gray-500 dark:text-gray-400 mt-1">Cliente: {{ quote.customer.name }}</p>
            </div>
            <SplitButton label="Acciones" :model="actionItems" severity="secondary" outlined class="mt-4 sm:mt-0" />
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-6">
                <!-- Flujo de Estatus -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b pb-3 mb-6">Flujo de Estatus</h2>
                    <div v-if="isTerminalStatus" class="text-center p-4 rounded-md"
                        :class="{ 'bg-red-50 dark:bg-red-900/20': quote.status === 'rechazada', 'bg-purple-50 dark:bg-purple-900/20': quote.status === 'venta_generada', 'bg-yellow-50 dark:bg-yellow-900/20': quote.status === 'expirada' }">
                        <p class="font-semibold"
                            :class="{ 'text-red-700 dark:text-red-300': quote.status === 'rechazada', 'text-purple-700 dark:text-purple-300': quote.status === 'venta_generada', 'text-yellow-700 dark:text-yellow-300': quote.status === 'expirada' }">
                            Esta cotización ha sido {{ quote.status.replace('_', ' ') }}.
                        </p>
                    </div>
                    <Stepper v-else v-model:value="activeIndex" class="basis-full">
                        <StepList>
                            <Step v-for="(step, index) in steps" :key="step.label" :value="index + 1" asChild>
                                <template #default="{ value, a11yAttrs }">
                                    <div class="flex-auto relative">
                                        <button
                                            class="bg-transparent border-0 inline-flex flex-col gap-2 items-center text-center w-full"
                                            @click="changeStatus(step.value, value)" v-bind="a11yAttrs.header"
                                            :disabled="!hasPermission('quotes.change_status')">
                                            <span
                                                :class="['w-12 h-12 rounded-full border-2 flex items-center justify-center transition-colors duration-200', { 'bg-primary border-primary text-primary-contrast': value <= activeIndex, 'border-surface-200 dark:border-surface-700': value > activeIndex, 'cursor-pointer hover:border-primary': value > activeIndex && hasPermission('quotes.change_status') }]"><i
                                                    :class="step.icon" /></span>
                                            <span
                                                :class="['font-medium text-sm', { 'text-primary': value <= activeIndex }]">{{
                                                    step.label }}</span>
                                        </button>
                                        <div v-if="index < steps.length - 1"
                                            class="absolute top-6 left-[calc(50%+1.5rem)] w-[calc(100%-3rem)]">
                                            <Divider />
                                        </div>
                                    </div>
                                </template>
                            </Step>
                        </StepList>
                    </Stepper>
                </div>
                <!-- Conceptos y Totales -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b pb-3 mb-4">Conceptos</h2>
                    <DataTable :value="quote.items" class="p-datatable-sm">
                        <Column field="description" header="Descripción"></Column>
                        <Column field="quantity" header="Cantidad"></Column>
                        <Column field="unit_price" header="Precio Unit."><template #body="{ data }">{{
                            formatCurrency(data.unit_price) }}</template></Column>
                        <Column field="line_total" header="Total"><template #body="{ data }">{{
                            formatCurrency(data.line_total) }}</template></Column>
                    </DataTable>
                    <div class="mt-4 flex justify-end">
                        <div class="w-full max-w-sm space-y-2 text-sm">
                            <div class="flex justify-between"><span>Subtotal:</span> <span>{{
                                formatCurrency(quote.subtotal) }}</span>
                            </div>
                            <div class="flex justify-between"><span>Descuento:</span> <span class="text-red-500">- {{
                                formatCurrency(quote.total_discount) }}</span></div>
                            <div class="flex justify-between"><span>Impuestos ({{ quote.tax_rate || 0 }}%):</span>
                                <span>{{
                                    formatCurrency(quote.total_tax) }}</span>
                            </div>
                            <div class="flex justify-between"><span>Envío:</span> <span>{{
                                formatCurrency(quote.shipping_cost) }}</span>
                            </div>
                            <div class="flex justify-between font-bold text-lg border-t pt-2 mt-2"><span>Total:</span>
                                <span>{{
                                    formatCurrency(quote.total_amount) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="lg:col-span-1 space-y-6">
                <!-- Historial de Versiones -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b pb-3 mb-4">Versiones</h2>
                    <ul class="space-y-2">
                        <li v-for="version in allVersions" :key="version.id">
                            <Link :href="route('quotes.show', version.id)"
                                class="block p-2 rounded-md transition-colors"
                                :class="version.id === quote.id ? 'bg-primary-50 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300' : 'hover:bg-gray-100 dark:hover:bg-gray-700'">
                            <div class="flex justify-between font-semibold">
                                <span>Versión {{ version.version_number }}</span>
                                <span>{{ formatCurrency(version.total_amount) }}</span>
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                Creada: {{ formatDate(version.created_at) }}
                            </div>
                            </Link>
                        </li>
                    </ul>
                </div>
                <!-- Historial de Actividad -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b pb-3 mb-6">Historial de Actividad</h2>
                    <div v-if="activities && activities.length > 0" class="relative max-h-[350px] overflow-y-auto pr-2">
                        <div class="relative pl-6">
                            <div class="absolute left-10 top-0 h-full border-l-2 border-gray-200 dark:border-gray-700">
                            </div>
                            <div class="space-y-8">
                                <div v-if="activities && activities.length > 0"
                                    class="relative max-h-[300px] overflow-y-auto pr-2">
                                    <div class="relative pl-6">
                                        <!-- Línea vertical del timeline -->
                                        <div
                                            class="absolute left-10 top-0 h-full border-l-2 border-gray-200 dark:border-gray-700">
                                        </div>

                                        <div class="space-y-8">
                                            <div v-for="activity in activities" :key="activity.id" class="relative">
                                                <!-- Ícono del evento -->
                                                <div class="absolute left-0 top-1.5 -translate-x-1/2">
                                                    <span
                                                        class="flex w-8 h-8 items-center justify-center text-white rounded-full z-10 shadow-md"
                                                        :class="{
                                                            'bg-blue-400': activity.event === 'created',
                                                            'bg-orange-400': activity.event === 'updated',
                                                            'bg-red-400': activity.event === 'deleted',
                                                            'bg-indigo-400': activity.event === 'status_changed',
                                                        }">
                                                        <i :class="{
                                                            'pi pi-plus': activity.event === 'created',
                                                            'pi pi-pencil': activity.event === 'updated',
                                                            'pi pi-trash': activity.event === 'deleted',
                                                            'pi pi-refresh': activity.event === 'status_changed',
                                                        }"></i>
                                                    </span>
                                                </div>

                                                <!-- Contenido del evento -->
                                                <div class="ml-10">
                                                    <h3
                                                        class="font-semibold text-gray-800 dark:text-gray-200 text-lg m-0">
                                                        {{
                                                            activity.description }}</h3>
                                                    <p class="text-xs text-gray-500 dark:text-gray-400">Por {{
                                                        activity.causer }} -
                                                        {{
                                                            activity.timestamp }}</p>

                                                    <!-- Contenido para eventos de actualización -->
                                                    <div v-if="activity.event === 'updated' && Object.keys(activity.changes.after).length > 0"
                                                        class="mt-3 text-sm space-y-2">
                                                        <div v-for="(value, key) in activity.changes.after" :key="key">
                                                            <p class="font-medium text-gray-700 dark:text-gray-300">{{
                                                                key }}</p>
                                                            <div v-if="key === 'Descripción'">
                                                                <DiffViewer :oldValue="activity.changes.before[key]"
                                                                    :newValue="value" />
                                                            </div>
                                                            <div v-else class="flex items-center gap-2 text-xs">
                                                                <span
                                                                    class="bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300 px-2 py-0.5 rounded line-through">{{
                                                                        activity.changes.before[key] || 'Vacío' }}</span>
                                                                <i class="pi pi-arrow-right text-gray-400"></i>
                                                                <span
                                                                    class="bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-300 px-2 py-0.5 rounded font-medium">{{
                                                                        value }}</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-center text-gray-500">No hay actividades.</div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>