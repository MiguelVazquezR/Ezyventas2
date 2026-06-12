<script setup>
import { ref, computed } from 'vue';
import { Head, router, usePage, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import OpenCashRegisterModal from './Partials/OpenCashRegisterModal.vue';
import CloseSessionModal from '@/Components/CloseSessionModal.vue';
import AddCashMovementModal from './Partials/AddCashMovementModal.vue';
import SessionHistoryModal from '@/Components/SessionHistoryModal.vue';
import { usePermissions } from '@/Composables';

const props = defineProps({
    cashRegister: Object,
    currentSession: Object,
    closedSessions: Object,
    branchUsers: Array,
    userBankAccounts: Array,
});

const page = usePage();
const confirm = useConfirm();
const { hasPermission } = usePermissions();

const showOpenModal = ref(false);
const showCloseModal = ref(false);
const showAddMovementModal = ref(false);
const showHistoryModal = ref(false);

// Estado para el movimiento seleccionado
const selectedMovement = ref(null);

const deleteRegister = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar la caja "${props.cashRegister.name}"?`,
        header: 'Confirmar eliminación',
        icon: 'pi pi-info-circle',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(route('cash-registers.destroy', props.cashRegister.id));
        }
    });
};

const actionItems = ref([
    { label: 'Editar caja', icon: 'pi pi-pencil', command: () => router.get(route('cash-registers.edit', props.cashRegister.id)) },
    { separator: true },
    { label: 'Eliminar', icon: 'pi pi-trash', class: 'text-red-500', command: deleteRegister },
]);

// Ref y función para el Menú de Acciones
const menu = ref();
const toggleMenu = (event) => {
    menu.value.toggle(event);
};

const isCurrentUserInSession = computed(() => {
    if (!props.currentSession || !props.currentSession.users) return false;
    const currentUserId = page.props.auth.user.id;
    return props.currentSession.users.some(user => user.id === currentUserId);
});

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

const joinSession = () => {
    router.post(route('cash-register-sessions.join', props.currentSession.id), {}, {
        preserveScroll: true,
    });
};

// Lógica de Movimientos
const openAddMovement = () => {
    selectedMovement.value = null; // Limpiar para agregar uno nuevo
    showAddMovementModal.value = true;
};

const editMovement = (movement) => {
    selectedMovement.value = movement; // Cargar datos para editar
    showAddMovementModal.value = true;
};

const deleteMovement = (movement) => {
    confirm.require({
        message: `¿Estás seguro de que deseas eliminar este movimiento ("${movement.description}") por ${formatCurrency(movement.amount)}? El saldo de la caja se recalculará automáticamente.`,
        header: 'Confirmar eliminación',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(route('session-cash-movements.destroy', movement.id), {
                preserveScroll: true,
            });
        }
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

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' }
};
</script>

<template>
    <Head :title="`Caja: ${cashRegister.name}`" />
    <AppLayout>
        
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            
            <!-- Breadcrumb / Botón de regreso -->
            <div class="flex items-center">
                <Link :href="route('cash-registers.index')" class="inline-flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors">
                    <i class="pi pi-arrow-left !text-[10px]"></i> Volver a cajas registradoras
                </Link>
            </div>

            <!-- Header de la página al estilo Tesla UI -->
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
                <div>
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0 flex items-center gap-4">
                        {{ cashRegister.name }}
                    </h1>
                    <div class="flex items-center gap-4 mt-3 flex-wrap">
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full" :class="cashRegister.is_active ? 'bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.8)] animate-pulse' : 'bg-gray-400 dark:bg-gray-600'"></span>
                            {{ cashRegister.is_active ? 'Activa' : 'Inactiva' }}
                        </p>
                        
                        <span class="text-gray-300 dark:text-gray-700 hidden sm:block">|</span>
                        
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">Sucursal:</span>
                            <span class="text-xs font-medium text-gray-900 dark:text-gray-100 flex items-center gap-1.5">
                                <i class="pi pi-building !text-[10px] text-gray-400"></i>
                                {{ cashRegister.branch.name }}
                            </span>
                        </div>
                    </div>
                </div>
                
                <div v-if="hasPermission('cash_registers.manage')" class="w-full md:w-auto shrink-0 flex gap-2">
                    <Button @click="toggleMenu" label="Opciones" icon="pi pi-chevron-down" iconPos="right" severity="secondary" outlined class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold w-full md:w-auto" />
                    <Menu ref="menu" :model="actionItems" :popup="true" :pt="menuPt" />
                </div>
            </div>

            <!-- Contenedor Principal (Grid Layout) -->
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                
                <!-- Columna Izquierda (Estado de Sesión) -->
                <div class="xl:col-span-1 space-y-6 flex flex-col">
                    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col h-full">
                        <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0 mb-6">Estatus Operativo</h2>
                        
                        <!-- ESTADO ABIERTO -->
                        <div v-if="currentSession" class="flex flex-col flex-grow">
                            <div class="flex flex-col items-center justify-center text-center p-6 bg-green-50 dark:bg-green-900/10 rounded-2xl border border-green-100 dark:border-green-900/30 mb-6 relative overflow-hidden group">
                                <div class="absolute inset-0 bg-green-500/5 group-hover:bg-green-500/10 transition-colors"></div>
                                <i class="pi pi-lock-open !text-4xl text-green-500 relative z-10 mb-3 drop-shadow-[0_0_8px_rgba(34,197,94,0.4)]"></i>
                                <p class="font-bold text-green-700 dark:text-green-400 m-0 uppercase tracking-widest text-sm relative z-10">Caja abierta</p>
                                <p class="text-xs text-green-600/80 dark:text-green-500/80 mt-1 m-0 relative z-10">{{ formatDate(currentSession.opened_at) }}</p>
                            </div>

                            <div class="space-y-4 flex-grow">
                                <div>
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0 block mb-2">Operadores en turno</span>
                                    <div class="flex items-center gap-3 bg-gray-50 dark:bg-[#1a1a1a] p-3 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                                        <div class="flex items-center gap-2 w-full">
                                            <AvatarGroup v-if="currentSession.users && currentSession.users.length > 0">
                                                <Avatar v-for="user in currentSession.users" :key="user.id" :label="user.name.charAt(0).toUpperCase()" shape="circle" class="!w-8 !h-8 !text-xs border-2 border-white dark:border-[#1a1a1a]" v-tooltip.bottom="user.name" />
                                            </AvatarGroup>
                                            <div v-if="!isCurrentUserInSession" class="ml-auto">
                                                <Button @click="joinSession" label="Unirse" icon="pi pi-sign-in" severity="success" text class="!rounded-xl !text-[10px] !uppercase !tracking-widest !font-bold !py-1 !px-3 bg-green-50 dark:bg-green-900/20" />
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0 block mb-2">Balance Financiero</span>
                                    <div class="bg-gray-50 dark:bg-[#1a1a1a] p-4 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] space-y-3">
                                        <div class="flex justify-between items-center text-sm text-gray-600 dark:text-gray-400">
                                            <span>Fondo inicial</span>
                                            <span class="font-mono">{{ formatCurrency(currentSession.opening_cash_balance) }}</span>
                                        </div>
                                        <div class="flex justify-between items-center pt-3 border-t border-gray-200 dark:border-[#2a2a2a]">
                                            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">Balance actual en caja</span>
                                            <span class="font-light tracking-tight text-xl text-gray-900 dark:text-white">{{ formatCurrency(currentCashBalance) }}</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex-grow flex flex-col">
                                    <div class="flex justify-between items-center mb-3">
                                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0 block">Movimientos manuales</span>
                                        <Button v-if="hasPermission('cash_registers.sessions.create_movements')" @click="openAddMovement" label="Agregar" icon="pi pi-plus" size="small" text class="!p-0 !text-[10px] !uppercase !tracking-widest !font-bold" />
                                    </div>
                                    
                                    <div class="bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a] overflow-hidden flex flex-col min-h-[120px]">
                                        <ul v-if="currentSession.cash_movements.length > 0" class="m-0 p-2 list-none space-y-1 max-h-48 overflow-y-auto custom-scrollbar">
                                            <li v-for="movement in currentSession.cash_movements" :key="movement.id" 
                                                class="flex justify-between items-center p-2 rounded-xl hover:bg-gray-100 dark:hover:bg-[#232323] transition-colors group">
                                                
                                                <div class="flex flex-col min-w-0 pr-2">
                                                    <span class="text-xs font-medium text-gray-900 dark:text-gray-100 truncate" :title="movement.description">{{ movement.description }}</span>
                                                    <span class="text-[9px] uppercase tracking-widest text-gray-500 mt-0.5" :class="movement.type === 'ingreso' ? 'text-green-500' : 'text-red-500'">
                                                        {{ movement.type === 'ingreso' ? 'Ingreso' : 'Retiro' }}
                                                    </span>
                                                </div>
                                                
                                                <div class="flex items-center gap-3 shrink-0">
                                                    <span class="font-mono text-sm font-bold" :class="movement.type === 'ingreso' ? 'text-green-500' : 'text-red-500'">
                                                        {{ movement.type === 'ingreso' ? '+' : '-' }}{{ formatCurrency(movement.amount) }}
                                                    </span>
                                                    
                                                    <!-- Botones Acción -->
                                                    <div class="flex">
                                                        <Button v-if="hasPermission('cash_registers.sessions.edit_movements')" 
                                                            icon="pi pi-pencil" text rounded 
                                                            @click="editMovement(movement)" 
                                                            size="small" class="!w-6 !h- !p-0 text-gray-400 hover:text-primary-500" v-tooltip.top="'Editar'" />
                                                        <Button v-if="hasPermission('cash_registers.sessions.delete_movements')" 
                                                            icon="pi pi-trash" text rounded 
                                                            @click="deleteMovement(movement)" 
                                                            size="small" class="!w-6 !h- !p-0 text-gray-400 hover:text-red-500" v-tooltip.top="'Eliminar'" />
                                                    </div>
                                                </div>
                                            </li>
                                        </ul>
                                        <div v-else class="flex flex-col items-center justify-center flex-grow py-6 opacity-50">
                                            <i class="pi pi-book !text-xl text-gray-400 mb-2"></i>
                                            <span class="text-[9px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin movimientos</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-6 pt-6 border-t border-gray-100 dark:border-[#3a3a3a] grid grid-cols-2 gap-3">
                                <Button @click="showHistoryModal = true" label="Auditoría" icon="pi pi-history" severity="secondary" outlined class="!rounded-xl !text-[10px] !uppercase !tracking-widest !font-bold" />
                                <Button v-if="isCurrentUserInSession" severity="contrast" @click="showCloseModal = true" label="Cerrar caja" icon="pi pi-lock" class="!rounded-xl !text-[10px] !uppercase !tracking-widest !font-bold border-none" />
                            </div>
                        </div>
                        
                        <!-- ESTADO CERRADO -->
                        <div v-else class="flex flex-col items-center justify-center text-center flex-grow py-12">
                            <div class="w-24 h-24 rounded-full bg-gray-50 dark:bg-[#1a1a1a] flex items-center justify-center border border-gray-100 dark:border-[#3a3a3a] mb-6">
                                <i class="pi pi-lock !text-4xl text-gray-400 dark:text-gray-600"></i>
                            </div>
                            <p class="font-light tracking-tight text-2xl text-gray-900 dark:text-white m-0 mb-2">Caja inactiva</p>
                            <p class="text-sm text-gray-500 m-0 max-w-[250px] mb-8">Esta terminal se encuentra cerrada y lista para operar un nuevo turno.</p>
                            
                            <Button @click="showOpenModal = true" label="Iniciar turno" icon="pi pi-key" severity="success" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold w-full max-w-[200px] !py-3 shadow-[0_4px_14px_rgba(34,197,94,0.4)]" />
                        </div>
                    </div>
                </div>
                
                <!-- Columna Derecha (Historial de Cortes) -->
                <div v-if="hasPermission('cash_registers.sessions.access')" class="xl:col-span-2 space-y-6 flex flex-col">
                    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col h-full">
                        <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0 mb-6">Historial de cortes de caja</h2>
                        
                        <DataTable :value="closedSessions.data" :pt="dataTablePt" responsiveLayout="scroll" :paginator="true" :rows="10" rowHover class="cursor-pointer">
                            
                            <Column field="id" header="Folio" sortable>
                                <template #body="{ data }">
                                    <span class="font-mono dark:text-gray-300">#{{ data.id }}</span>
                                </template>
                            </Column>
                            
                            <Column field="opener.name" header="Abrió sesión" sortable>
                                <template #body="{ data }">
                                    <div class="flex items-center gap-2">
                                        <Avatar :label="data.opener.name.charAt(0).toUpperCase()" shape="circle" class="!w-6 !h-6 !text-[10px]" />
                                        <span class="font-medium">{{ data.opener.name }}</span>
                                    </div>
                                </template>
                            </Column>
                            
                            <Column field="closed_at" header="Fecha de cierre" sortable>
                                <template #body="{ data }">
                                    <span class="text-gray-600 dark:text-gray-400">{{ formatDate(data.closed_at) }}</span>
                                </template>
                            </Column>
                            
                            <Column field="cash_difference" header="Diferencia" sortable>
                                <template #body="{ data }">
                                    <div class="flex items-center gap-1">
                                        <span class="font-mono font-bold text-sm" :class="data.cash_difference < 0 ? 'text-red-500' : (data.cash_difference > 0 ? 'text-green-500' : 'text-gray-400')">
                                            {{ data.cash_difference > 0 ? '+' : '' }}{{ formatCurrency(data.cash_difference) }}
                                        </span>
                                    </div>
                                </template>
                            </Column>
                            
                            <Column headerStyle="width: 5rem; text-align: center">
                                <template #body="{ data }">
                                    <Button @click="router.visit(route('cash-register-sessions.show', data.id))" icon="pi pi-arrow-right" text rounded class="!w-8 !h-8 !text-gray-400 hover:!bg-gray-200 dark:hover:!bg-[#2a2a2a] !transition-colors" v-tooltip.top="'Ver detalle del corte'" />
                                </template>
                            </Column>
                            
                            <template #empty>
                                <div class="flex flex-col items-center justify-center text-center py-10 opacity-60">
                                    <i class="pi pi-calculator !text-3xl text-gray-400 mb-3"></i>
                                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin historial</p>
                                    <p class="text-xs text-gray-400 mt-1">No se han realizado cortes en esta caja registradora.</p>
                                </div>
                            </template>
                        </DataTable>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modales -->
        <OpenCashRegisterModal v-if="cashRegister" :visible="showOpenModal" :cash-register="cashRegister" :branch-users="branchUsers" :user-bank-accounts="userBankAccounts" @update:visible="showOpenModal = false" />
        <CloseSessionModal v-if="currentSession" :visible="showCloseModal" :session="currentSession" @update:visible="showCloseModal = false" />
        <AddCashMovementModal v-if="currentSession" :visible="showAddMovementModal" :session="currentSession" :movement-to-edit="selectedMovement" @update:visible="showAddMovementModal = false" />
        <SessionHistoryModal v-if="currentSession" :visible="showHistoryModal" :session="currentSession" @update:visible="showHistoryModal = false" />
    </AppLayout>
</template>