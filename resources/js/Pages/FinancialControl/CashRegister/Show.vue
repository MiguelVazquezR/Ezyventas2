<script setup>
import { ref, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import OpenCashRegisterModal from './Partials/OpenCashRegisterModal.vue';
import CloseSessionModal from '@/Components/CloseSessionModal.vue';
import AddCashMovementModal from './Partials/AddCashMovementModal.vue';
// NUEVO: Importar el modal de historial
import SessionHistoryModal from '@/Components/SessionHistoryModal.vue';
import { usePermissions } from '@/Composables';

const props = defineProps({
    cashRegister: Object,
    currentSession: Object,
    closedSessions: Object,
    branchUsers: Array,
});

const page = usePage();
const confirm = useConfirm();
const { hasPermission } = usePermissions();

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Cajas', url: route('cash-registers.index') },
    { label: props.cashRegister.name }
]);

const showOpenModal = ref(false);
const showCloseModal = ref(false);
const showAddMovementModal = ref(false);
// NUEVO: Estado para el modal de historial
const showHistoryModal = ref(false);

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

// NUEVO: Lógica para saber si el usuario actual está en la sesión
const isCurrentUserInSession = computed(() => {
    if (!props.currentSession || !props.currentSession.users) return false;
    const currentUserId = page.props.auth.user.id;
    return props.currentSession.users.some(user => user.id === currentUserId);
});

// NUEVO: Lógica para calcular el balance actual
const currentCashBalance = computed(() => {
    if (!props.currentSession) return 0;
    const openingBalance = parseFloat(props.currentSession.opening_cash_balance) || 0;
    const cashSales = (props.currentSession.payments || [])
        .filter(p => p && p.payment_method === 'efectivo' && p.status === 'completado')
        .reduce((sum, p) => sum + parseFloat(p.amount), 0);
    const inflows = (props.currentSession.cash_movements || [])
        .filter(m => m.type === 'ingreso')
        .reduce((sum, m) => sum + parseFloat(m.amount), 0);
    const outflows = (props.currentSession.cash_movements || [])
        .filter(m => m.type === 'egreso')
        .reduce((sum, m) => sum + parseFloat(m.amount), 0);
    return openingBalance + cashSales + inflows - outflows;
});

// NUEVO: Método para unirse a la sesión
const joinSession = () => {
    router.post(route('cash-register-sessions.join', props.currentSession.id), {}, {
        preserveScroll: true,
    });
};

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
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mt-4 mb-6">
            <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-200">{{ cashRegister.name }}</h1>
            <SplitButton v-if="hasPermission('cash_registers.manage')" label="Acciones" :model="actionItems" severity="secondary" outlined class="mt-4 sm:mt-0" />
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-1 space-y-6">
                <Card>
                    <template #title>Estatus Actual</template>
                    <template #content>
                        <div v-if="currentSession">
                            <div class="text-center">
                                <i class="pi pi-lock-open !text-5xl text-green-500"></i>
                                <p class="mt-4 font-semibold m-0">Caja abierta</p>
                                <p class="text-sm text-gray-500 m-0">Sesión iniciada por <span class="font-medium text-gray-700 dark:text-gray-300">{{ currentSession.opener?.name }}</span></p>
                                <p class="text-xs text-gray-400 m-0">{{ formatDate(currentSession.opened_at) }}</p>
                            </div>

                            <div class="mt-4 border-t pt-4">
                                <h5 class="font-semibold text-left m-0">Usuarios en sesión</h5>
                                <div v-if="currentSession.users && currentSession.users.length > 0" class="flex justify-center">
                                    <AvatarGroup>
                                        <Avatar v-for="user in currentSession.users" :key="user.id" :label="user.name.charAt(0).toUpperCase()" size="large" shape="circle" v-tooltip.bottom="user.name" />
                                    </AvatarGroup>
                                </div>
                                <div v-if="!isCurrentUserInSession" class="mt-4">
                                    <Button @click="joinSession" label="Unirse a la Sesión" icon="pi pi-sign-in" class="w-full" severity="success" outlined />
                                </div>
                            </div>

                            <div class="mt-4 border-t pt-4 text-left text-sm space-y-2">
                                <div class="flex justify-between">
                                    <span>Fondo inicial:</span>
                                    <span class="font-mono font-semibold">{{ formatCurrency(currentSession.opening_cash_balance) }}</span>
                                </div>
                                <div class="flex justify-between font-bold text-base mt-2">
                                    <span>Balance actual en caja:</span>
                                    <span class="font-mono">{{ formatCurrency(currentCashBalance) }}</span>
                                </div>
                            </div>

                            <div class="mt-4 border-t pt-4">
                                <div class="flex justify-between items-center">
                                    <h5 class="font-semibold text-left m-0">Movimientos de efectivo</h5>
                                    <Button v-if="hasPermission('cash_registers.sessions.create_movements')" @click="showAddMovementModal = true" label="Agregar" icon="pi pi-plus" size="small" text />
                                </div>
                                <ul v-if="currentSession.cash_movements.length > 0" class="mt-2 text-left text-sm space-y-2 max-h-32 overflow-y-auto">
                                    <li v-for="movement in currentSession.cash_movements" :key="movement.id" class="flex justify-between">
                                        <span>{{ movement.description }}</span>
                                        <span :class="movement.type === 'ingreso' ? 'text-green-500' : 'text-red-500'">{{ formatCurrency(movement.amount) }}</span>
                                    </li>
                                </ul>
                                <p v-else class="text-xs text-gray-400 mt-2 text-center">No hay movimientos.</p>
                            </div>

                            <div class="mt-6 space-y-2">
                                <Button @click="showHistoryModal = true" label="Ver historial de sesión" icon="pi pi-history" severity="secondary" outlined class="w-full" />
                                <Button v-if="isCurrentUserInSession" @click="showCloseModal = true" label="Cerrar o salir de caja" class="w-full" />
                            </div>
                        </div>
                        <div v-else class="text-center">
                            <i class="pi pi-lock !text-5xl text-gray-400"></i>
                            <p class="mt-4 font-semibold">Caja cerrada</p>
                            <p class="text-sm text-gray-500 m-0">Esta caja está disponible para una nueva sesión.</p>
                            <Button @click="showOpenModal = true" label="Abrir Caja" severity="success" class="w-full mt-6" />
                        </div>
                    </template>
                </Card>
                <Card>
                    <template #title>Detalles de la caja</template>
                    <template #content>
                        <ul class="space-y-3 text-sm">
                            <li class="flex justify-between"><span>Nombre</span><span class="font-medium">{{ cashRegister.name }}</span></li>
                            <li class="flex justify-between"><span>Sucursal</span><span class="font-medium">{{ cashRegister.branch.name }}</span></li>
                            <li class="flex justify-between"><span>Estatus</span><Tag :value="cashRegister.is_active ? 'Activa' : 'Inactiva'" :severity="cashRegister.is_active ? 'success' : 'danger'" /></li>
                        </ul>
                    </template>
                </Card>
            </div>
            <div v-if="hasPermission('cash_registers.sessions.access')" class="lg:col-span-2">
                <Card>
                    <template #title>Historial de cortes de caja</template>
                    <template #content>
                        <DataTable :value="closedSessions.data" class="p-datatable-sm" responsiveLayout="scroll" :paginator="true" :rows="10">
                            <template #empty>
                                <div class="text-center py-4">No hay historial de cortes para esta caja.</div>
                            </template>
                            <Column field="id" header="ID Sesión"></Column>
                            <Column field="opener.name" header="Abrió sesión"></Column>
                            <Column field="closed_at" header="Fecha de cierre"><template #body="{ data }">{{ formatDate(data.closed_at) }}</template></Column>
                            <Column field="cash_difference" header="Diferencia">
                                <template #body="{ data }">
                                    <span :class="data.cash_difference < 0 ? 'text-red-500' : (data.cash_difference > 0 ? 'text-green-500' : '')">{{ formatCurrency(data.cash_difference) }}</span>
                                </template>
                            </Column>
                            <Column>
                                <template #body="{ data }">
                                    <Button @click="$inertia.visit(route('cash-register-sessions.show', data.id))" icon="pi pi-search" text rounded v-tooltip.bottom="'Ver detalle del corte'" />
                                </template>
                            </Column>
                        </DataTable>
                    </template>
                </Card>
            </div>
        </div>

        <!-- Modales -->
        <OpenCashRegisterModal v-if="cashRegister" :visible="showOpenModal" :cash-register="cashRegister" :branch-users="branchUsers" @update:visible="showOpenModal = false" />
        <CloseSessionModal v-if="currentSession" :visible="showCloseModal" :session="currentSession" @update:visible="showCloseModal = false" />
        <AddCashMovementModal v-if="currentSession" :visible="showAddMovementModal" :session="currentSession" @update:visible="showAddMovementModal = false" />
        <SessionHistoryModal v-if="currentSession" :visible="showHistoryModal" :session="currentSession" @update:visible="showHistoryModal = false" />
    </AppLayout>
</template>