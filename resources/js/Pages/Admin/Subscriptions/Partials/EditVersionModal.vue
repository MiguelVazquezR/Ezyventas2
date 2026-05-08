<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    visible: Boolean,
    currentVersion: Object,
    planItems: Array
});

const emit = defineEmits(['update:visible']);

// --- FORMULARIO DE EDICIÓN (INERTIA) ---
const editForm = useForm({
    start_date: '',
    end_date: '',
    limits: {}
});

const toInputDate = (dateString) => {
    if (!dateString) return '';
    const d = new Date(dateString);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

// Sincronizar data al abrir el modal
watch(() => props.visible, (newVal) => {
    if (newVal && props.currentVersion) {
        editForm.start_date = toInputDate(props.currentVersion.start_date);
        editForm.end_date = toInputDate(props.currentVersion.end_date);
        
        const limitsObj = {};
        props.planItems.filter(p => p.type === 'limit').forEach(planItem => {
            const existing = props.currentVersion.items.find(i => i.item_key === planItem.key);
            limitsObj[planItem.key] = existing ? existing.quantity : (planItem.meta?.quantity || 1);
        });
        
        editForm.limits = limitsObj;
    }
});

const submitEditVersion = () => {
    editForm.put(route('admin.subscriptions.update-version', props.currentVersion.id), {
        preserveScroll: true,
        onSuccess: () => {
            emit('update:visible', false);
        }
    });
};

// --- TESLA UI PT ---
const dialogPt = {
    root: { class: 'dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] rounded-3xl shadow-2xl overflow-hidden custom-scrollbar' },
    header: { class: 'bg-gray-50 dark:bg-[#1a1a1a] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-5' },
    title: { class: 'text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0' },
    content: { class: 'p-6 dark:bg-[#232323] custom-scrollbar' },
    footer: { class: 'bg-gray-50 dark:bg-[#1a1a1a] border-t border-gray-100 dark:border-[#3a3a3a] px-6 py-4' },
    closeButton: { class: 'hover:bg-gray-200 dark:hover:bg-[#2a2a2a] transition-colors rounded-full w-8 h-8 flex items-center justify-center' },
    closeButtonIcon: { class: 'dark:text-gray-400 !text-sm' },
};

const inputNumberPt = {
    input: { root: { class: 'w-full min-w-0 !rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2.5 !text-sm text-gray-900 dark:text-white' } }
};

const inputDatePt = 'w-full min-w-0 rounded-xl bg-white dark:bg-[#1a1a1a] border-gray-200 dark:border-[#3a3a3a] focus:dark:border-primary-500 transition-colors py-2.5 text-sm text-gray-900 dark:text-white dark:[color-scheme:dark]';
</script>

<template>
    <Dialog :visible="visible" @update:visible="emit('update:visible', $event)" modal header="Ajuste de plan y recursos" :style="{ width: '45rem' }" :pt="dialogPt">
        <template #header>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center text-blue-500 flex-shrink-0">
                    <i class="pi pi-sliders-h !text-lg"></i>
                </div>
                <div>
                    <h2 class="text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0 mb-0.5">Ajuste de plan y recursos</h2>
                    <p class="text-[9px] uppercase tracking-widest text-gray-500 m-0">Modificación manual administrativa</p>
                </div>
            </div>
        </template>
        
        <form @submit.prevent="submitEditVersion" class="space-y-6">
            <!-- Ajuste de Fechas -->
            <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <h3 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 mb-4 flex items-center gap-2">
                    <i class="pi pi-calendar"></i> Vigencia del plan
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Fecha de Inicio *</label>
                        <input type="date" v-model="editForm.start_date" :class="inputDatePt" required />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Fecha de Vencimiento *</label>
                        <input type="date" v-model="editForm.end_date" :class="inputDatePt" required />
                    </div>
                </div>
            </div>

            <!-- Ajuste de Límites -->
            <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <h3 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 mb-4 flex items-center gap-2">
                    <i class="pi pi-chart-pie"></i> Asignación de Recursos (Límites)
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <!-- Iteramos todos los items tipo 'limit' y pasamos el atributo 'fluid' nativo de PrimeVue -->
                    <div v-for="limItem in planItems.filter(p => p.type === 'limit')" :key="limItem.key" class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 truncate" :title="limItem.name">
                            {{ limItem.name }}
                        </label>
                        <InputNumber v-model="editForm.limits[limItem.key]" fluid class="font-mono w-full" :pt="inputNumberPt" />
                        <small class="text-[9px] text-gray-400">(-1 = Ilimitado)</small>
                    </div>
                </div>
            </div>

            <div class="flex gap-3 justify-end pt-4 border-t border-gray-100 dark:border-[#3a3a3a]">
                <Button label="Cancelar" icon="pi pi-times" @click="emit('update:visible', false)" severity="secondary" outlined class="!rounded-xl !text-xs !uppercase !tracking-wider" :disabled="editForm.processing" />
                <Button label="Guardar ajustes" icon="pi pi-check" type="submit" severity="primary" class="!rounded-xl !text-xs !uppercase !tracking-wider" :loading="editForm.processing" />
            </div>
        </form>
    </Dialog>
</template>