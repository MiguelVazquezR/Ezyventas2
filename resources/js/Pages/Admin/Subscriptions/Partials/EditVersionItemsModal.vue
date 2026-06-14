<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    visible: Boolean,
    version: Object,
    planItems: Array,
});

const emit = defineEmits(['update:visible']);

const editForm = useForm({
    start_date: '',
    end_date: '',
    limits: {},
    modules: {},
});

const toInputDate = (dateString) => {
    if (!dateString) return '';
    const d = new Date(dateString);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

watch(() => props.visible, (newVal) => {
    if (newVal && props.version) {
        editForm.start_date = toInputDate(props.version.start_date);
        editForm.end_date = toInputDate(props.version.end_date);

        const limitsObj = {};
        const modulesObj = {};

        props.planItems.forEach((planItem) => {
            const existing = props.version.items
                ? props.version.items.find(i => i.item_key === planItem.key)
                : null;

            if (planItem.type === 'limit') {
                limitsObj[planItem.key] = existing ? existing.quantity : (planItem.meta?.default_quantity || 0);
            }

            if (planItem.type === 'module') {
                modulesObj[planItem.key] = !!existing;
            }
        });

        editForm.limits = limitsObj;
        editForm.modules = modulesObj;
    }
}, { immediate: true });

const submit = () => {
    editForm.put(route('admin.subscriptions.update-version-items', props.version.id), {
        preserveScroll: true,
        onSuccess: () => {
            emit('update:visible', false);
        },
    });
};

// --- TESLA UI PT ---
const dialogPt = {
    root: { class: 'dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] rounded-3xl shadow-2xl overflow-hidden' },
    header: { class: 'bg-gray-50 dark:bg-[#1a1a1a] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-5' },
    title: { class: 'text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0' },
    content: { class: 'p-6 dark:bg-[#232323]' },
    footer: { class: 'bg-gray-50 dark:bg-[#1a1a1a] border-t border-gray-100 dark:border-[#3a3a3a] px-6 py-4' },
};

const inputNumberPt = {
    input: { root: { class: 'w-full min-w-0 !rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2.5 !text-sm text-gray-900 dark:text-white' } },
};

const inputDatePt = 'w-full min-w-0 rounded-xl bg-white dark:bg-[#1a1a1a] border-gray-200 dark:border-[#3a3a3a] focus:dark:border-primary-500 transition-colors py-2.5 text-sm text-gray-900 dark:text-white dark:[color-scheme:dark]';

const togglePt = {
    root: { class: '!w-10 !h-5' },
    input: { class: '!rounded-full' },
};
</script>

<template>
    <Dialog
        :visible="visible"
        @update:visible="emit('update:visible', $event)"
        modal
        :style="{ width: '50rem' }"
        :pt="dialogPt"
    >
        <template #header>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-amber-50 dark:bg-amber-900/30 flex items-center justify-center text-amber-500 flex-shrink-0">
                    <i class="pi pi-pencil !text-lg"></i>
                </div>
                <div>
                    <h2 class="text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0 mb-0.5">Editar versión y sus items</h2>
                    <p class="text-[9px] uppercase tracking-widest text-gray-500 m-0">Ajuste de fechas, módulos y límites</p>
                </div>
            </div>
        </template>

        <form @submit.prevent="submit" class="space-y-6">
            <!-- Fechas -->
            <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <h3 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 mb-4 flex items-center gap-2">
                    <i class="pi pi-calendar"></i> Vigencia
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Fecha de inicio *</label>
                        <input type="date" v-model="editForm.start_date" :class="inputDatePt" required />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Fecha de vencimiento *</label>
                        <input type="date" v-model="editForm.end_date" :class="inputDatePt" required />
                    </div>
                </div>
            </div>

            <!-- Módulos -->
            <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <h3 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 mb-4 flex items-center gap-2">
                    <i class="pi pi-box"></i> Módulos activos
                </h3>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                    <div
                        v-for="modItem in planItems.filter(p => p.type === 'module')"
                        :key="modItem.key"
                        class="flex items-center justify-between py-2 px-3 rounded-xl bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a]"
                    >
                        <label class="text-xs text-gray-700 dark:text-gray-300 cursor-pointer select-none" :for="'mod-' + modItem.key">
                            {{ modItem.name }}
                        </label>
                        <ToggleSwitch
                            :inputId="'mod-' + modItem.key"
                            v-model="editForm.modules[modItem.key]"
                            :pt="togglePt"
                        />
                    </div>
                </div>
            </div>

            <!-- Límites -->
            <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <h3 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 mb-4 flex items-center gap-2">
                    <i class="pi pi-chart-pie"></i> Recursos (Límites)
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div
                        v-for="limItem in planItems.filter(p => p.type === 'limit')"
                        :key="limItem.key"
                        class="flex flex-col gap-1.5"
                    >
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 truncate" :title="limItem.name">
                            {{ limItem.name }}
                        </label>
                        <InputNumber v-model="editForm.limits[limItem.key]" fluid :pt="inputNumberPt" />
                        <small class="text-[9px] text-gray-400">(-1 = Ilimitado)</small>
                    </div>
                </div>
            </div>

            <div class="flex gap-3 justify-end pt-4 border-t border-gray-100 dark:border-[#3a3a3a]">
                <Button
                    label="Cancelar"
                    icon="pi pi-times"
                    @click="emit('update:visible', false)"
                    severity="secondary"
                    outlined
                    class="!rounded-xl !text-xs !uppercase !tracking-wider"
                    :disabled="editForm.processing"
                />
                <Button
                    label="Guardar cambios"
                    icon="pi pi-check"
                    type="submit"
                    severity="primary"
                    class="!rounded-xl !text-xs !uppercase !tracking-wider"
                    :loading="editForm.processing"
                />
            </div>
        </form>
    </Dialog>
</template>
