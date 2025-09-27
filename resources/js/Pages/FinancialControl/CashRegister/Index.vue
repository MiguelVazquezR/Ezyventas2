<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from "primevue/useconfirm";
import { usePermissions } from '@/Composables';

const props = defineProps({
    cashRegisters: Array,
});

const confirm = useConfirm();

// composables
const { hasPermission } = usePermissions();

const menu = ref();
const selectedRegisterForMenu = ref(null);

const deleteRegister = () => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar la caja "${selectedRegisterForMenu.value.name}"?`,
        header: 'Confirmar Eliminación',
        icon: 'pi pi-info-circle',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(route('cash-registers.destroy', selectedRegisterForMenu.value.id), {
                preserveScroll: true,
            });
        }
    });
};

const menuItems = ref([
    { 
        label: 'Ver Detalles', 
        icon: 'pi pi-eye', 
        command: () => router.get(route('cash-registers.show', selectedRegisterForMenu.value.id)),
    },
    { 
        label: 'Editar Caja', 
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
</script>

<template>
    <Head title="Gestión de Cajas" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 md:p-6">
                <!-- Header -->
                <div class="mb-6 flex justify-between items-center">
                     <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Gestión de Cajas Registradoras</h1>
                     <Button v-if="hasPermission('cash_registers.manage')" label="Nueva Caja" icon="pi pi-plus" @click="router.get(route('cash-registers.create'))" severity="warning" />
                </div>

                <!-- Tabla de Cajas -->
                <DataTable :value="cashRegisters" dataKey="id" tableStyle="min-width: 50rem">
                     <template #empty>
                        <div class="text-center py-4">No hay cajas registradoras creadas.</div>
                    </template>
                    <Column field="name" header="Nombre"></Column>
                    <Column field="branch.name" header="Sucursal"></Column>
                    <Column field="is_active" header="Estatus">
                        <template #body="{ data }">
                            <Tag :value="data.is_active ? 'Activa' : 'Inactiva'" :severity="data.is_active ? 'success' : 'danger'" />
                        </template>
                    </Column>
                     <Column field="in_use" header="En Uso">
                        <template #body="{ data }">
                             <i class="pi" :class="{ 'pi-check-circle text-green-500': data.in_use, 'pi-times-circle text-gray-400': !data.in_use }"></i>
                        </template>
                    </Column>
                    <Column v-if="hasPermission('cash_registers.manage')" headerStyle="width: 5rem; text-align: center">
                        <template #body="{ data }"> <Button @click="toggleMenu($event, data)" icon="pi pi-ellipsis-v" text rounded severity="secondary" /> </template>
                    </Column>
                </DataTable>
                
                <Menu ref="menu" :model="menuItems" :popup="true" />
            </div>
        </div>
    </AppLayout>
</template>