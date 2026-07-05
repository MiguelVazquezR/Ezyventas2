<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import ProgressBar from 'primevue/progressbar';

const props = defineProps({
    visible: Boolean,
});

const emit = defineEmits(['update:visible']);

const isLoading = ref(false);
const isDownloading = ref(false);
const ownProductsCount = ref(0);
const catalogProductsCount = ref(0);
const fetchError = ref('');

const fetchCounts = async () => {
    isLoading.value = true;
    fetchError.value = '';
    try {
        const response = await axios.get(route('import-export.products.export-info'));
        ownProductsCount.value = response.data.own_products;
        catalogProductsCount.value = response.data.catalog_products;
    } catch (error) {
        fetchError.value = 'No se pudieron cargar los datos. Intenta de nuevo.';
    } finally {
        isLoading.value = false;
    }
};

const handleExport = () => {
    isDownloading.value = true;
    // Small delay so the user sees the loading state, then trigger download
    setTimeout(() => {
        window.location.href = route('import-export.products.export');
        setTimeout(() => {
            isDownloading.value = false;
            emit('update:visible', false);
        }, 1500);
    }, 600);
};

watch(() => props.visible, (newVal) => {
    if (newVal) {
        isDownloading.value = false;
        fetchCounts();
    }
});

// --- TESLA UI PT ---
const dialogPt = {
    root: { class: 'dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] rounded-3xl shadow-2xl overflow-hidden' },
    header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-5' },
    title: { class: 'text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0' },
    content: { class: 'dark:bg-[#232323] p-6 lg:p-8' },
    closeButton: { class: 'hover:bg-gray-100 dark:hover:bg-[#1a1a1a] transition-colors rounded-full w-8 h-8 flex items-center justify-center' },
    closeButtonIcon: { class: 'dark:text-gray-400 !text-sm' },
    mask: { class: 'bg-gray-900/60 dark:bg-black/80' }
};
</script>

<template>
    <Dialog
        :visible="visible"
        @update:visible="emit('update:visible', $event)"
        modal
        class="w-full max-w-lg mx-4"
        :pt="dialogPt"
        :closable="!isDownloading"
    >
        <template #header>
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-full bg-green-50 dark:bg-green-900/20 text-green-500 flex items-center justify-center flex-shrink-0 border border-green-100 dark:border-green-900/30">
                    <i class="pi pi-file-excel !text-sm"></i>
                </div>
                <div>
                    <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">Exportar a Excel</h2>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">Descarga de catálogo</p>
                </div>
            </div>
        </template>

        <!-- Loading skeleton -->
        <div v-if="isLoading" class="flex flex-col items-center justify-center py-10 gap-4">
            <i class="pi pi-spinner pi-spin !text-2xl text-gray-400"></i>
            <p class="text-xs text-gray-400 m-0">Cargando información...</p>
        </div>

        <!-- Error -->
        <div v-else-if="fetchError" class="flex flex-col items-center gap-4 py-6">
            <div class="w-12 h-12 rounded-full bg-red-50 dark:bg-red-900/20 flex items-center justify-center border border-red-100 dark:border-red-900/30">
                <i class="pi pi-exclamation-triangle !text-lg text-red-500"></i>
            </div>
            <p class="text-sm text-red-600 dark:text-red-400 m-0">{{ fetchError }}</p>
            <Button label="Reintentar" icon="pi pi-refresh" text @click="fetchCounts" class="!rounded-xl !text-xs !uppercase !tracking-widest !font-bold" />
        </div>

        <!-- Content -->
        <template v-else>
            <!-- Info alert -->
            <div class="bg-blue-50 dark:bg-blue-900/10 p-4 rounded-2xl flex items-start gap-3 border border-blue-100 dark:border-blue-900/30 mb-6">
                <i class="pi pi-info-circle mt-0.5 !text-lg text-blue-500"></i>
                <div>
                    <p class="text-[10px] font-bold text-blue-500 dark:text-blue-400 uppercase tracking-widest m-0 mb-1">Solo productos propios</p>
                    <p class="text-xs text-blue-800 dark:text-blue-300 m-0 leading-relaxed">
                        Únicamente se exportarán los productos que hayas creado tú. Los productos del <strong>catálogo base de Ezyventas</strong> no se incluyen en la exportación.
                    </p>
                </div>
            </div>

            <!-- Count cards -->
            <div class="grid grid-cols-2 gap-4 mb-6">
                <div class="bg-green-50 dark:bg-green-900/10 border border-green-100 dark:border-green-900/20 rounded-2xl p-4 text-center">
                    <p class="text-[10px] uppercase tracking-widest font-bold text-green-600 dark:text-green-400 m-0 mb-1">Tus productos</p>
                    <p class="text-3xl font-light tracking-tight text-green-700 dark:text-green-300 m-0">{{ ownProductsCount }}</p>
                    <p class="text-[10px] text-green-500 dark:text-green-500 mt-1 m-0 uppercase tracking-wider">Se exportarán</p>
                </div>
                <div class="bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] rounded-2xl p-4 text-center">
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-1">Catálogo base</p>
                    <p class="text-3xl font-light tracking-tight text-gray-400 dark:text-gray-500 m-0">{{ catalogProductsCount }}</p>
                    <p class="text-[10px] text-gray-400 mt-1 m-0 uppercase tracking-wider">No se exportarán</p>
                </div>
            </div>

            <!-- Downloading state -->
            <div v-if="isDownloading" class="flex flex-col items-center gap-4 py-4">
                <i class="pi pi-spinner pi-spin !text-2xl text-primary-500"></i>
                <p class="text-xs text-gray-500 m-0">Generando archivo Excel...</p>
                <ProgressBar mode="indeterminate" class="w-full !h-1" />
            </div>

            <!-- Empty state -->
            <div v-else-if="ownProductsCount === 0" class="flex flex-col items-center gap-3 py-8">
                <div class="w-14 h-14 rounded-full bg-gray-100 dark:bg-[#1a1a1a] flex items-center justify-center border border-gray-200 dark:border-[#3a3a3a]">
                    <i class="pi pi-inbox !text-xl text-gray-400"></i>
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400 m-0">No tienes productos propios para exportar.</p>
                <p class="text-xs text-gray-400 m-0">Crea productos primero y vuelve a intentarlo.</p>
            </div>
        </template>

        <template #footer>
            <div class="flex justify-end gap-3 mt-2 pt-6 border-t border-gray-100 dark:border-[#3a3a3a] w-full">
                <Button
                    label="Cancelar"
                    text severity="secondary"
                    @click="emit('update:visible', false)"
                    :disabled="isDownloading"
                    class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold"
                />
                <Button
                    v-if="!isLoading && !fetchError && ownProductsCount > 0"
                    :label="isDownloading ? 'Descargando...' : 'Descargar Excel'"
                    :icon="isDownloading ? 'pi pi-spinner pi-spin' : 'pi pi-download'"
                    @click="handleExport"
                    :disabled="isDownloading"
                    severity="success"
                    class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-6 shadow-sm"
                />
            </div>
        </template>
    </Dialog>
</template>
