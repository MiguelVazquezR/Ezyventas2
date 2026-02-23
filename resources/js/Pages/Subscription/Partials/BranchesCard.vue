<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useConfirm } from 'primevue/useconfirm';
import BranchModal from '@/Components/BranchModal.vue';

const props = defineProps({
    subscription: Object,
    branchLimit: Object,
    branchUsage: Number,
    branchLimitReached: Boolean
});

const confirm = useConfirm();

const isBranchModalVisible = ref(false);
const selectedBranch = ref(null);

const openCreateBranchModal = () => {
    selectedBranch.value = null;
    isBranchModalVisible.value = true;
};

const openEditBranchModal = (branch) => {
    selectedBranch.value = branch;
    isBranchModalVisible.value = true;
};

const confirmDeleteBranch = (branch) => {
    confirm.require({
        message: `¿Estás seguro de que quieres eliminar la sucursal "${branch.name}"?`,
        header: 'Confirmar Eliminación',
        icon: 'pi pi-exclamation-triangle',
        accept: () => {
            router.delete(route('branches.destroy', branch.id), { preserveScroll: true });
        }
    });
};
</script>

<template>
    <Card>
        <template #title>
            <div class="flex justify-between items-center">
                <span>Sucursales</span>
                <Button @click="openCreateBranchModal" icon="pi pi-plus" size="small"
                    v-tooltip.bottom="branchLimitReached ? 'Límite de sucursales alcanzado' : 'Nueva Sucursal'" />
            </div>
        </template>
        <template #content>
            <DataTable :value="subscription.branches" stripedRows size="small">
                <Column field="name" header="Nombre"></Column>
                <Column field="contact_email" header="Email"></Column>
                <Column field="contact_phone" header="Teléfono"></Column>
                <Column header="Principal">
                    <template #body="slotProps">
                        <i v-if="slotProps.data.is_main" class="pi pi-check-circle text-green-500"
                            v-tooltip.bottom="'Sucursal Principal'"></i>
                    </template>
                </Column>
                <Column>
                    <template #body="slotProps">
                        <div class="flex justify-end gap-2">
                            <Button @click="openEditBranchModal(slotProps.data)" icon="pi pi-pencil"
                                text rounded size="small" />
                            <Button @click="confirmDeleteBranch(slotProps.data)" icon="pi pi-trash" text
                                rounded severity="danger" size="small"
                                :disabled="slotProps.data.is_main" />
                        </div>
                    </template>
                </Column>
            </DataTable>
            
            <BranchModal 
                :visible="isBranchModalVisible" 
                :branch="selectedBranch" 
                :limit="branchLimit?.quantity"
                :usage="branchUsage" 
                @update:visible="isBranchModalVisible = $event" 
            />
        </template>
    </Card>
</template>