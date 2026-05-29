<script setup>
const props = defineProps({
    visible: Boolean,
    operatingHours: Array,
});

const emit = defineEmits(['update:visible']);

const daysOfWeek = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

// --- Tesla UI PT ---
const dialogPt = {
    root: { class: 'dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] rounded-3xl shadow-2xl overflow-hidden' },
    header: { class: 'bg-gray-50 dark:bg-[#1a1a1a] border-b border-gray-100 dark:border-[#3a3a3a] px-5 py-4' },
    title: { class: 'text-sm font-medium text-gray-900 dark:text-white tracking-tight m-0' },
    content: { class: 'p-5 dark:bg-[#232323]' },
    closeButton: { class: 'hover:bg-gray-200 dark:hover:bg-[#2a2a2a] transition-colors rounded-full w-8 h-8 flex items-center justify-center' },
    closeButtonIcon: { class: 'dark:text-gray-400 !text-sm' },
};

const inputMaskPt = {
    root: {
        class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2 !text-sm !text-gray-900 dark:!text-white !w-20 !text-center',
    },
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="emit('update:visible', $event)" modal header="Establecer horario semanal"
        :style="{ width: '32rem' }" :pt="dialogPt">
        
        <template #header>
            <div class="flex items-center gap-2.5">
                <div class="w-9 h-9 rounded-xl bg-primary-500/10 flex items-center justify-center">
                    <i class="pi pi-clock text-primary-500 !text-sm"></i>
                </div>
                <div>
                    <h3 class="text-sm font-medium text-gray-900 dark:text-white m-0">Establecer horario semanal</h3>
                    <p class="text-[9px] uppercase tracking-wider text-gray-500 m-0">Define los días y horas de operación</p>
                </div>
            </div>
        </template>

        <div v-if="operatingHours" class="space-y-2">
            <!-- Cabecera de la tabla -->
            <div class="grid grid-cols-[auto_1fr_auto_1fr] gap-3 items-center px-2 mb-1">
                <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 col-span-1"></span>
                <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 text-center">Apertura</span>
                <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 text-center"></span>
                <span class="text-[9px] uppercase tracking-widest font-bold text-gray-400 text-center">Cierre</span>
            </div>

            <div v-for="(day, dayIndex) in operatingHours" :key="day.day"
                class="grid grid-cols-[auto_1fr_auto_1fr] gap-3 items-center p-2.5 rounded-xl transition-colors"
                :class="day.open ? 'bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a]' : 'opacity-50'"
            >
                <!-- Checkbox + día -->
                <div class="flex items-center gap-2 min-w-[100px]">
                    <Checkbox :inputId="'day_open_' + dayIndex" v-model="day.open" :binary="true" />
                    <label :for="'day_open_' + dayIndex" class="text-xs font-medium text-gray-700 dark:text-gray-300 m-0 cursor-pointer select-none">
                        {{ day.day }}
                    </label>
                </div>

                <!-- Desde -->
                <InputMask v-model="day.from" mask="99:99" placeholder="09:00" :disabled="!day.open" :pt="inputMaskPt" />

                <!-- Separador -->
                <span class="text-xs text-gray-400 font-medium justify-self-center">—</span>

                <!-- Hasta -->
                <InputMask v-model="day.to" mask="99:99" placeholder="18:00" :disabled="!day.open" :pt="inputMaskPt" />
            </div>
        </div>

        <template #footer>
            <Button label="Listo" icon="pi pi-check" @click="emit('update:visible', false)"
                class="!rounded-full !text-xs !uppercase !tracking-wider" autofocus />
        </template>
    </Dialog>
</template>
