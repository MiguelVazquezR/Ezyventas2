<script setup>
import { ref, computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import { usePermissions } from '@/Composables';

const props = defineProps({
    cashRegisters: Array,
    cashRegisterLimit: Number,
    cashRegisterUsage: Number,
});

const confirm = useConfirm();
const { hasPermission } = usePermissions();

// --- Lógica de Límites ---
const limitReached = computed(() => {
    if (props.cashRegisterLimit === -1) return false;
    return props.cashRegisterUsage >= props.cashRegisterLimit;
});

const menu = ref();
const selectedRegisterForMenu = ref(null);

const deleteRegister = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar la caja "${selectedRegisterForMenu.value.name}"?`,
        header: 'Confirmar eliminación',
        icon: 'pi pi-info-circle',
        acceptClass: 'p-button-danger',
        rejectProps: { label: 'Cancelar', severity: 'secondary', outlined: true },
        acceptProps: { label: 'Eliminar', severity: 'danger' },
        accept: () => {
            router.delete(route('cash-registers.destroy', selectedRegisterForMenu.value.id), {
                preserveScroll: true,
            });
        }
    });
};

const menuItems = ref([
    {
        label: 'Ver detalles',
        icon: 'pi pi-eye',
        command: () => router.get(route('cash-registers.show', selectedRegisterForMenu.value.id)),
    },
    {
        label: 'Editar caja',
        icon: 'pi pi-pencil',
        command: () => router.get(route('cash-registers.edit', selectedRegisterForMenu.value.id))
    },
    { separator: true },
    {
        label: 'Eliminar',
        icon: 'pi pi-trash',
        class: 'text-red-500',
        command: deleteRegister
    },
]);

const toggleMenu = (event, data) => {
    selectedRegisterForMenu.value = data;
    menu.value.toggle(event);
};

const onRowClick = (event) => {
    const target = event.originalEvent.target;
    if (target.closest('button') || target.closest('.p-button')) {
        return;
    }
    router.get(route('cash-registers.show', event.data.id));
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
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' }
};
</script>

<template>
    <Head title="Gestión de Cajas" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">

            <!-- Banner de Alerta de Límite -->
            <div v-if="limitReached" class="bg-orange-50 dark:bg-orange-900/10 border border-orange-200 dark:border-orange-800 rounded-2xl p-4 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div class="flex items-center gap-3">
                    <i class="pi pi-exclamation-circle text-orange-500 !text-xl"></i>
                    <div>
                        <p class="font-bold text-sm text-orange-800 dark:text-orange-400 m-0">Límite de cajas alcanzado</p>
                        <p class="text-xs text-orange-700 dark:text-orange-300/80 m-0 mt-0.5">Has alcanzado el límite de cajas registradoras de tu plan actual.</p>
                    </div>
                </div>
                <Link :href="route('subscription.manage')">
                    <Button label="Mejorar plan" size="small" severity="warning" class="!rounded-xl !uppercase !tracking-widest !text-[10px] !font-bold" />
                </Link>
            </div>

            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                
                <!-- Header con Título y Botón -->
                <div class="mb-8 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Cajas registradoras</h1>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.8)] animate-pulse"></span>
                            Terminales de cobro operativas
                        </p>
                    </div>
                    
                    <div v-tooltip.bottom="limitReached ? `Límite de ${cashRegisterLimit} cajas alcanzado` : 'Crear nueva caja'">
                        <Button v-if="hasPermission('cash_registers.manage')" label="Nueva caja" icon="pi pi-plus"
                            @click="router.get(route('cash-registers.create'))" severity="warning"
                            :disabled="limitReached" class="!rounded-xl !text-xs !uppercase !tracking-wider" />
                    </div>
                </div>

                <!-- Tabla de Cajas -->
                <DataTable :value="cashRegisters" dataKey="id" tableStyle="min-width: 50rem"
                    @row-click="onRowClick" rowHover class="cursor-pointer" :pt="dataTablePt">
                    
                    <Column field="name" header="Nombre / Identificador" sortable>
                        <template #body="{ data }">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                                    <i class="pi pi-desktop text-blue-500 !text-xs"></i>
                                </div>
                                <span class="font-medium text-gray-900 dark:text-gray-100 m-0">{{ data.name }}</span>
                            </div>
                        </template>
                    </Column>
                    
                    <Column field="branch.name" header="Sucursal asignada" sortable>
                        <template #body="{ data }">
                            <span class="text-gray-600 dark:text-gray-400 flex items-center gap-1.5">
                                <i class="pi pi-building !text-[10px]"></i> {{ data.branch?.name || '--' }}
                            </span>
                        </template>
                    </Column>
                    
                    <Column field="is_active" header="Estatus de servicio" sortable>
                        <template #body="{ data }">
                            <Tag :value="data.is_active ? 'Activa' : 'Inactiva'"
                                :severity="data.is_active ? 'success' : 'secondary'" :pt="tagPt" />
                        </template>
                    </Column>
                    
                     <Column field="in_use" header="Estado de sesión" sortable>
                        <template #body="{ data }">
                            <div class="flex items-center gap-2" :class="data.in_use ? 'text-green-600 dark:text-green-400' : 'text-gray-500'">
                                <span class="w-2 h-2 rounded-full shadow-sm" :class="data.in_use ? 'bg-green-500 shadow-green-500/50 animate-pulse' : 'bg-gray-300 dark:bg-gray-600'"></span>
                                <span class="text-[10px] font-bold uppercase tracking-widest">{{ data.in_use ? 'Sesión abierta' : 'Cerrada' }}</span>
                            </div>
                        </template>
                    </Column>
                    
                    <Column v-if="hasPermission('cash_registers.manage')" headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }"> 
                            <Button @click.stop="toggleMenu($event, data)" icon="pi pi-ellipsis-v"
                                text rounded class="!w-8 !h-8 !text-gray-400 hover:!bg-gray-200 dark:hover:!bg-[#2a2a2a] !transition-colors" /> 
                        </template>
                    </Column>

                    <template #empty>
                        <div class="flex flex-col items-center justify-center text-center py-10">
                            <i class="pi pi-desktop !text-3xl text-gray-400 mb-3"></i>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin registros</p>
                            <p class="text-xs text-gray-400 mt-1">No hay cajas registradoras creadas en esta sucursal.</p>
                        </div>
                    </template>
                </DataTable>

                <Menu ref="menu" :model="menuItems" :popup="true" :pt="menuPt" />
            </div>
        </div>
    </AppLayout>
</template>