<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from 'primevue/useconfirm';
import DiffViewer from '@/Components/DiffViewer.vue';
import PatternLock from '@/Components/PatternLock.vue';
import { usePermissions } from '@/Composables';

const props = defineProps({
    serviceOrder: Object,
    activities: Array,
});

const confirm = useConfirm();

// composables
const { hasPermission } = usePermissions();

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Órdenes de Servicio', url: route('service-orders.index') },
    { label: `Orden #${props.serviceOrder.id}` }
]);

// --- Lógica del Flujo de Estatus ---
const steps = ref([
    { label: 'Pendiente', value: 'pendiente', icon: 'pi pi-inbox' },
    { label: 'En Progreso', value: 'en_progreso', icon: 'pi pi-cog' },
    { label: 'Esperando Refacción', value: 'esperando_refaccion', icon: 'pi pi-wrench' },
    { label: 'Terminado', value: 'terminado', icon: 'pi pi-check-circle' },
    { label: 'Entregado', value: 'entregado', icon: 'pi pi-send' }
]);

const activeIndex = computed(() => {
    const index = steps.value.findIndex(step => step.value === props.serviceOrder.status);
    return index >= 0 ? index + 1 : 0; // Stepper es 1-based, 0 si está cancelado
});

const isCancelled = computed(() => props.serviceOrder.status === 'cancelado');

const changeStatus = (newStatusValue, newIndex) => {
    if (newIndex < activeIndex.value || isCancelled.value) return;

    const newStatusLabel = steps.value.find(s => s.value === newStatusValue)?.label || newStatusValue;

    confirm.require({
        message: `¿Estás seguro de que quieres cambiar el estatus a "${newStatusLabel}"?`,
        header: 'Confirmar Cambio de Estatus',
        icon: 'pi pi-sync',
        accept: () => {
            router.patch(route('service-orders.updateStatus', props.serviceOrder.id), { status: newStatusValue }, { preserveScroll: true });
        }
    });
};

const cancelOrder = () => {
    confirm.require({
        message: 'Al cancelar la orden, su estatus cambiará a "Cancelado" y no podrá seguir avanzando en el flujo. ¿Estás seguro?',
        header: 'Confirmar Cancelación',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: 'Sí, cancelar orden',
        rejectLabel: 'No, mantener',
        accept: () => {
            router.patch(route('service-orders.updateStatus', props.serviceOrder.id), { status: 'cancelado' }, { preserveScroll: true });
        }
    });
};

const deleteOrder = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar esta orden de servicio? Esta acción no se puede deshacer.`,
        header: 'Confirmar Eliminación',
        accept: () => {
            router.delete(route('service-orders.destroy', props.serviceOrder.id));
        }
    });
};

const actionItems = ref([
    { label: 'Crear nueva orden', icon: 'pi pi-plus', command: () => router.get(route('service-orders.create')), visible: hasPermission('services.orders.create') },
    { label: 'Editar orden', icon: 'pi pi-pencil', command: () => router.get(route('service-orders.edit', props.serviceOrder.id)), visible: hasPermission('services.orders.edit') },
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: deleteOrder, visible: hasPermission('services.orders.delete') },
]);

// --- Helpers de Formato ---
const whatsappLink = computed(() => {
    if (!props.serviceOrder.customer_phone) return '#';
    const sanitizedPhone = props.serviceOrder.customer_phone.replace(/\D/g, '');
    if (sanitizedPhone.length === 10) {
        return `https://wa.me/52${sanitizedPhone}`;
    }
    return `https://wa.me/${sanitizedPhone}`;
});

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleString('es-MX', { dateStyle: 'long', timeStyle: 'short' });
};

const getStatusSeverity = (status) => {
    const map = { pendiente: 'info', en_progreso: 'warning', esperando_refaccion: 'secondary', terminado: 'success', entregado: 'primary', cancelado: 'danger' };
    return map[status] || 'secondary';
};

const formatCurrency = (value) => {
    if (value === null || value === undefined) return 'N/A';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};
</script>

<template>

    <Head :title="`Orden de Servicio #${serviceOrder.id}`" />
    <AppLayout>
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />

        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mt-4 mb-6">
            <div>
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">Orden de Servicio #{{ serviceOrder.id }}
                </h1>
                <p class="text-gray-500 dark:text-gray-400 mt-1">Cliente: {{ serviceOrder.customer_name }}</p>
            </div>
            <div class="flex items-center gap-2 mt-4 sm:mt-0">
                <Button v-if="!isCancelled && hasPermission('services.orders.cancel')" @click="cancelOrder" label="Cancelar Orden" severity="danger" outlined />
                <SplitButton label="Acciones" :model="actionItems" severity="secondary" outlined></SplitButton>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Flujo de Estatus Visual -->
            <div class="col-span-full bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                <h2 class="text-lg font-semibold border-b pb-3 mb-6">Flujo de Estatus</h2>
                <div v-if="isCancelled" class="text-center p-4 bg-red-50 dark:bg-red-900/20 rounded-md">
                    <i class="pi pi-times-circle text-red-500 text-3xl"></i>
                    <p class="mt-2 font-semibold text-red-700 dark:text-red-300">Esta orden ha sido cancelada.</p>
                </div>
                <Stepper v-else v-model:value="activeIndex" class="basis-full">
                    <StepList>
                        <Step v-for="(step, index) in steps" :key="step.label" :value="index + 1"
                            v-slot="{ activateCallback, value, a11yAttrs }" asChild>
                            <div class="flex flex-row flex-auto" v-bind="a11yAttrs.root">
                                <button class="bg-transparent border-0 inline-flex flex-col gap-2 items-center"
                                    :class="index == 4 ? 'w-32' : 'w-60'" @click="changeStatus(step.value, value)"
                                    v-bind="a11yAttrs.header"
                                    :disabled="!hasPermission('services.orders.change_status')">
                                    <span
                                        :class="['size-12 rounded-full border-2 flex items-center justify-center transition-colors duration-200', { 'bg-primary border-primary text-primary-contrast': value <= activeIndex, 'border-surface-200 dark:border-surface-700': value > activeIndex, 'cursor-pointer hover:border-primary': value > activeIndex && hasPermission('services.orders.change_status') }]">
                                        <i :class="step.icon" />
                                    </span>
                                    <span :class="['font-medium text-xs', { 'text-primary': value <= activeIndex }]">{{
                                        step.label }}</span>
                                </button>
                                <Divider v-if="index != 4" />
                            </div>
                        </Step>
                    </StepList>
                </Stepper>
            </div>
            <!-- Columna Principal -->
            <div class="lg:col-span-2 space-y-6">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div v-if="hasPermission('services.orders.see_customer_info')">
                            <h2 class="text-lg font-semibold border-b pb-3 mb-4">Información del Cliente</h2>
                            <ul class="space-y-3 text-sm">
                                <li class="flex items-center"><i class="pi pi-user w-6 text-gray-500"></i> <span
                                        class="font-medium">{{
                                            serviceOrder.customer_name }}</span></li>
                                <li v-if="serviceOrder.customer_phone" class="flex items-center">
                                    <i class="pi pi-phone w-6 text-gray-500"></i>
                                    <span class="font-medium">{{ serviceOrder.customer_phone }}</span>
                                    <a :href="whatsappLink" target="_blank" class="ml-auto">
                                        <Button icon="pi pi-whatsapp" severity="success" text rounded
                                            v-tooltip.bottom="'Enviar WhatsApp'" />
                                    </a>
                                </li>
                                <li class="flex items-center"><i class="pi pi-envelope w-6 text-gray-500"></i> <span
                                        class="font-medium">{{ serviceOrder.customer_email || 'N/A' }}</span></li>
                            </ul>
                        </div>
                        <div>
                            <h2 class="text-lg font-semibold border-b pb-3 mb-4">Información de la Orden</h2>
                            <ul class="space-y-3 text-sm">
                                <li class="flex justify-between"><span>Estatus Actual</span>
                                    <Tag :value="serviceOrder.status.replace('_', ' ')"
                                        :severity="getStatusSeverity(serviceOrder.status)" class="capitalize" />
                                </li>
                                <li class="flex justify-between"><span>Fecha de Recepción</span> <span>{{
                                    formatDate(serviceOrder.received_at) }}</span></li>
                                <li class="flex justify-between"><span>Fecha Promesa</span> <span>{{
                                    formatDate(serviceOrder.promised_at) }}</span></li>
                                <li class="flex justify-between"><span>Técnico Asignado</span> <span>{{
                                    serviceOrder.technician_name ||
                                    'Sin asignar' }}</span></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- NUEVA SECCIÓN: Refacciones y Mano de Obra -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b pb-3 mb-4">Refacciones y Mano de Obra</h2>
                    <DataTable :value="serviceOrder.items" class="p-datatable-sm">
                        <Column field="description" header="Descripción"></Column>
                        <Column field="quantity" header="Cantidad" style="width: 6rem" class="text-center"></Column>
                        <Column field="unit_price" header="Precio Unit." style="width: 10rem" class="text-right">
                            <template #body="{ data }">{{ formatCurrency(data.unit_price) }}</template>
                        </Column>
                        <Column field="line_total" header="Total" style="width: 10rem" class="text-right font-semibold">
                            <template #body="{ data }">{{ formatCurrency(data.line_total) }}</template>
                        </Column>
                    </DataTable>
                    <div v-if="!serviceOrder.items || serviceOrder.items.length === 0"
                        class="text-center text-gray-500 py-4">
                        No se han agregado refacciones o mano de obra.
                    </div>
                    <div class="flex justify-end mt-4">
                        <div class="w-full max-w-xs text-right space-y-2">
                            <div class="flex justify-between font-bold text-lg border-t pt-2 mt-2">
                                <span>Total Final:</span>
                                <span>{{ formatCurrency(serviceOrder.final_total) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b pb-3 mb-4">Detalles del Equipo y Falla</h2>
                    <div>
                        <p class="font-semibold">Descripción del Equipo</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">{{ serviceOrder.item_description }}</p>
                    </div>
                    <div class="mt-4">
                        <p class="font-semibold">Problemas Reportados por el Cliente</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">{{ serviceOrder.reported_problems }}
                        </p>
                    </div>
                    <div v-if="serviceOrder.technician_diagnosis" class="mt-4 pt-4 border-t">
                        <p class="font-semibold">Diagnóstico del Técnico</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">{{ serviceOrder.technician_diagnosis }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Columna Derecha -->
            <div class="lg:col-span-1 space-y-6">
                <!-- Evidencia Inicial -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b pb-3 mb-4">Evidencia Inicial</h2>
                    <Galleria
                        :value="serviceOrder.media?.filter(m => m.collection_name === 'initial-service-order-evidence')"
                        :numVisible="4" containerStyle="max-width: 100%" :showThumbnails="false" :showIndicators="true">
                        <template #item="slotProps">
                            <img :src="slotProps.item.original_url" :alt="`Evidencia ${slotProps.index}`"
                                class="w-full max-h-96 object-contain" />
                        </template>
                    </Galleria>
                    <div v-if="!serviceOrder.media || serviceOrder.media?.filter(m => m.collection_name === 'initial-service-order-evidence').length === 0"
                        class="text-center text-gray-500 py-4">
                        No se adjuntaron imágenes.
                    </div>
                </div>
                <!-- Detalles Adicionales (Campos Personalizados) -->
                <div v-if="serviceOrder.custom_fields && Object.keys(serviceOrder.custom_fields).length > 0"
                    class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold border-b pb-3 mb-4">Detalles Adicionales</h2>
                    <ul class="space-y-4 text-sm">
                        <li v-for="(value, key) in serviceOrder.custom_fields" :key="key">
                            <span class="font-semibold capitalize">{{ key.replace('_', ' ') }}</span>
                            <div v-if="key === 'patron_desbloqueo'" class="mt-2">
                                <PatternLock :modelValue="value" :edit="false" />
                            </div>
                            <p v-else class="text-gray-700 dark:text-gray-300">{{ value === true ? 'Sí' : value
                                === false ? 'No' : value
                            }}</p>
                        </li>
                    </ul>
                </div>
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
                    <div v-else class="text-center text-gray-500 py-8"> No hay actividades registradas. </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>