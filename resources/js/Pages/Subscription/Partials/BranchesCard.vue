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
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(route('branches.destroy', branch.id), { preserveScroll: true });
        }
    });
};

// --- TESLA UI PASS-THROUGH (PT) ---
const dataTablePt = {
    root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
    headerRow: { class: 'bg-gray-50 dark:bg-[#1a1a1a]' },
    headerCell: { class: 'bg-transparent text-[10px] uppercase tracking-widest text-gray-500 font-bold py-4 px-4 border-b border-gray-100 dark:border-[#3a3a3a]' },
    bodyRow: { class: 'dark:bg-[#232323] hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors text-sm text-gray-700 dark:text-gray-300 group' },
    bodyCell: { class: 'py-4 px-4 border-b border-gray-50 dark:border-[#2a2a2a]' },
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[9px] !uppercase !tracking-widest !font-bold' }
};
</script>

<template>
    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
        
        <!-- Header -->
        <div class="mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-indigo-50 dark:bg-indigo-900/20 flex items-center justify-center flex-shrink-0 border border-indigo-100 dark:border-indigo-900/30">
                    <i class="pi pi-sitemap !text-sm text-indigo-500"></i>
                </div>
                <div>
                    <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Sucursales</h2>
                    <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Gestión de ubicaciones operativas</p>
                </div>
            </div>
            
            <Button @click="openCreateBranchModal" label="Nueva sucursal" icon="pi pi-plus"
                :disabled="branchLimitReached"
                severity="primary"
                class="!rounded-xl !uppercase !tracking-widest !text-[10px] !font-bold w-full sm:w-auto shadow-sm"
                v-tooltip.bottom="branchLimitReached ? 'Límite de sucursales alcanzado' : 'Añadir nueva sucursal'" />
        </div>

        <!-- Tabla -->
        <div class="flex-grow overflow-x-auto">
            <DataTable :value="subscription.branches" :pt="dataTablePt" responsiveLayout="scroll">
                
                <Column field="name" header="Nombre">
                    <template #body="{ data }">
                        <span class="font-medium text-gray-900 dark:text-gray-100 m-0">{{ data.name }}</span>
                    </template>
                </Column>
                
                <Column field="contact_email" header="Email">
                    <template #body="{ data }">
                        <span class="text-xs text-gray-600 dark:text-gray-400 m-0">{{ data.contact_email || '--' }}</span>
                    </template>
                </Column>
                
                <Column field="contact_phone" header="Teléfono">
                    <template #body="{ data }">
                        <span class="text-xs font-mono text-gray-600 dark:text-gray-400 m-0">{{ data.contact_phone || '--' }}</span>
                    </template>
                </Column>
                
                <Column header="Tipo" style="width: 8rem;">
                    <template #body="{ data }">
                        <Tag v-if="data.is_main" value="Principal" severity="success" :pt="tagPt" />
                        <Tag v-else value="Adicional" severity="secondary" :pt="tagPt" />
                    </template>
                </Column>
                
                <Column headerStyle="width: 6rem; text-align: right;">
                    <template #body="{ data }">
                        <div class="flex justify-end gap-1 opacity-100 sm:opacity-0 group-hover:opacity-100 transition-opacity">
                            <Button @click="openEditBranchModal(data)" icon="pi pi-pencil" text rounded class="!w-8 !h-8 !p-0 text-gray-400 hover:text-primary-500" v-tooltip.top="'Editar'" />
                            <Button @click="confirmDeleteBranch(data)" icon="pi pi-trash" text rounded class="!w-8 !h-8 !p-0 text-gray-400 hover:text-red-500" :disabled="data.is_main" v-tooltip.top="data.is_main ? 'La sucursal principal nu se puede eliminar' : 'Eliminar'" />
                        </div>
                    </template>
                </Column>
                
                <template #empty>
                    <div class="flex flex-col items-center justify-center text-center py-8 opacity-60">
                        <i class="pi pi-sitemap !text-3xl text-gray-400 mb-3"></i>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin sucursales adicionales</p>
                        <p class="text-xs text-gray-400 mt-1">Solo tienes la sucursal principal registrada.</p>
                    </div>
                </template>
            </DataTable>
        </div>
        
        <BranchModal 
            :visible="isBranchModalVisible" 
            :branch="selectedBranch" 
            :limit="branchLimit?.quantity"
            :usage="branchUsage" 
            @update:visible="isBranchModalVisible = $event" 
        />
    </div>
</template>