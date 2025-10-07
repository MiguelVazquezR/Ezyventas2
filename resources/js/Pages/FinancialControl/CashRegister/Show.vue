<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import OpenCashRegisterModal from './Partials/OpenCashRegisterModal.vue';
import CloseCashRegisterModal from './Partials/CloseCashRegisterModal.vue';
import AddCashMovementModal from './Partials/AddCashMovementModal.vue';
import { usePermissions } from '@/Composables';

const props = defineProps({
    cashRegister: Object,
    currentSession: Object,
    closedSessions: Object,
    branchUsers: Array,
});

const confirm = useConfirm();

// composables
const { hasPermission } = usePermissions();

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Cajas', url: route('cash-registers.index') },
    { label: props.cashRegister.name }
]);

const showOpenModal = ref(false);
const showCloseModal = ref(false);
const showAddMovementModal = ref(false);

const deleteRegister = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar la caja "${props.cashRegister.name}"?`,
        header: 'Confirmar Eliminación',
        icon: 'pi pi-info-circle',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(route('cash-registers.destroy', props.cashRegister.id));
        }
    });
};

const actionItems = ref([
    { label: 'Editar Caja', icon: 'pi pi-pencil', command: () => router.get(route('cash-registers.edit', props.cashRegister.id)) },
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: deleteRegister },
]);

const formatCurrency = (value) => {
    if (value === null || value === undefined) return 'N/A';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
};
</script>

<template>

    <Head :title="`Caja: ${cashRegister.name}`" />
    <AppLayout>
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />

        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mt-4 mb-6">
            <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">{{ cashRegister.name }}</h1>
            <SplitButton v-if="hasPermission('cash_registers.manage')" label="Acciones" :model="actionItems" severity="secondary" outlined class="mt-4 sm:mt-0">
            </SplitButton>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Columna Izquierda: Estatus y Detalles -->
            <div class="lg:col-span-1 space-y-6">
                <Card>
                    <template #title>Estatus Actual</template>
                    <template #content>
                        <div v-if="currentSession" class="text-center">
                            <i class="pi pi-lock-open !text-5xl text-green-500"></i>
                            <p class="mt-4 font-semibold">Caja Abierta</p>
                            <p class="text-sm text-gray-500">Sesión iniciada por <span
                                    class="font-medium text-gray-700 dark:text-gray-300">{{ currentSession.user.name
                                    }}</span></p>
                            <p class="text-xs text-gray-400">{{ formatDate(currentSession.opened_at) }}</p>
                            <div class="mt-4 text-left text-sm space-y-2">
                                <div class="flex justify-between"><span>Fondo Inicial:</span> <span
                                        class="font-mono font-semibold">{{
                                            formatCurrency(currentSession.opening_cash_balance) }}</span></div>
                            </div>
                            <div class="mt-4 border-t pt-4">
                                <div class="flex justify-between items-center">
                                    <h4 class="font-semibold text-left">Movimientos de Efectivo</h4>
                                    <Button v-if="hasPermission('cash_registers.sessions.create_movements')" @click="showAddMovementModal = true" label="Agregar Movimiento"
                                        icon="pi pi-plus" size="small" text />
                                </div>
                                <ul v-if="currentSession.cash_movements.length > 0"
                                    class="mt-2 text-left text-sm space-y-2 max-h-32 overflow-y-auto">
                                    <li v-for="movement in currentSession.cash_movements" :key="movement.id"
                                        class="flex justify-between">
                                        <span>{{ movement.description }}</span>
                                        <span
                                            :class="movement.type === 'ingreso' ? 'text-green-500' : 'text-red-500'">{{
                                                formatCurrency(movement.amount) }}</span>
                                    </li>
                                </ul>
                                <p v-else class="text-xs text-gray-400 mt-2 text-center">No hay movimientos en esta
                                    sesión.</p>
                            </div>
                            <Button @click="showCloseModal = true" label="Realizar Corte" class="w-full mt-6" />
                        </div>
                        <div v-else class="text-center">
                            <i class="pi pi-lock !text-5xl text-gray-400"></i>
                            <p class="mt-4 font-semibold">Caja Cerrada</p>
                            <p class="text-sm text-gray-500">Esta caja está disponible para iniciar una nueva sesión.
                            </p>
                            <Button @click="showOpenModal = true" label="Abrir Caja" severity="success"
                                class="w-full mt-6" />
                        </div>
                    </template>
                </Card>
                <Card>
                    <template #title>Detalles de la Caja</template>
                    <template #content>
                        <ul class="space-y-3 text-sm">
                            <li class="flex justify-between">
                                <span class="text-gray-500">Nombre</span>
                                <span class="font-medium">{{ cashRegister.name }}</span>
                            </li>
                            <li class="flex justify-between">
                                <span class="text-gray-500">Sucursal</span>
                                <span class="font-medium">{{ cashRegister.branch.name }}</span>
                            </li>
                            <li class="flex justify-between">
                                <span class="text-gray-500">Estatus</span>
                                <Tag :value="cashRegister.is_active ? 'Activa' : 'Inactiva'"
                                    :severity="cashRegister.is_active ? 'success' : 'danger'" />
                            </li>
                        </ul>
                    </template>
                </Card>
            </div>

            <!-- Columna Derecha: Historial de Cortes -->
            <div v-if="hasPermission('cash_registers.sessions.access')" class="lg:col-span-2">
                <Card>
                    <template #title>Historial de Cortes de Caja</template>
                    <template #content>
                        <DataTable :value="closedSessions.data" class="p-datatable-sm" responsiveLayout="scroll"
                            :paginator="true" :rows="10">
                            <template #empty>
                                <div class="text-center py-4">No hay historial de cortes para esta caja.</div>
                            </template>
                            <Column field="id" header="ID Sesión"></Column>
                            <Column field="user.name" header="Cajero"></Column>
                            <Column field="closed_at" header="Fecha de Cierre">
                                <template #body="{ data }">{{ formatDate(data.closed_at) }}</template>
                            </Column>
                            <Column field="cash_difference" header="Diferencia">
                                <template #body="{ data }">
                                    <span
                                        :class="data.cash_difference < 0 ? 'text-red-500' : (data.cash_difference > 0 ? 'text-green-500' : '')">
                                        {{ formatCurrency(data.cash_difference) }}
                                    </span>
                                </template>
                            </Column>
                            <Column>
                                <template #body="{ data }">
                                    <Button @click="$inertia.visit(route('cash-register-sessions.show', data.id))" icon="pi pi-search" text rounded
                                        v-tooltip.bottom="'Ver detalle del corte'" />
                                </template>
                            </Column>
                        </DataTable>
                    </template>
                </Card>
            </div>
        </div>

        <!-- Modales -->
        <OpenCashRegisterModal v-if="cashRegister" :visible="showOpenModal" :cash-register="cashRegister"
            :branch-users="branchUsers" @update:visible="showOpenModal = false" />
        <CloseCashRegisterModal v-if="currentSession" :visible="showCloseModal" :session="currentSession"
            @update:visible="showCloseModal = false" />
        <AddCashMovementModal v-if="currentSession" :visible="showAddMovementModal" :session="currentSession"
            @update:visible="showAddMovementModal = false" />
    </AppLayout>
</template>