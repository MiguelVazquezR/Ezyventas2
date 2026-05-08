<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    form: {
        type: Object,
        required: true
    },
    isEdit: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['submit']);

const itemTypes = [
    { label: 'Módulo', value: 'module' },
    { label: 'Límite', value: 'limit' }
];

// --- TESLA UI PASS-THROUGH (PT) ---
const inputPt = {
    root: { class: 'w-full min-w-0 !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3 !text-sm text-gray-900 dark:text-white' }
};

const selectPt = {
    root: { class: 'w-full !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] hover:dark:!border-[#4a4a4a] transition-colors' },
    input: { class: '!py-3 !text-sm text-gray-900 dark:text-white' },
    panel: { class: 'dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !rounded-2xl !shadow-2xl' },
    option: { class: 'dark:hover:!bg-[#232323] !transition-colors !text-sm' }
};

const textareaPt = {
    root: { class: 'w-full min-w-0 !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3 !text-sm text-gray-900 dark:text-white custom-scrollbar' }
};

const inputNumberPt = {
    input: { root: { class: 'w-full min-w-0 !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3 !text-sm text-gray-900 dark:text-white' } }
};

const telemetryNumberPt = {
    input: { root: { class: 'w-full min-w-0 !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-4 !text-4xl !font-light tracking-tight text-gray-900 dark:!text-white' } }
};

const switchPt = {
    slider: { class: '!rounded-full' }
};

const cancel = () => {
    router.get(route('admin.plan-items.index'));
};
</script>

<template>
    <form @submit.prevent="emit('submit')" class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <!-- Columna Izquierda: Datos Principales -->
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white dark:bg-[#1a1a1a] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                <h2 class="text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0 mb-6 flex items-center gap-2">
                    <i class="pi pi-receipt !text-sm text-gray-400"></i>
                    Información general
                </h2>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <!-- Nombre -->
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Nombre visible *</label>
                        <InputText v-model="form.name" placeholder="Ej. Punto de Venta" :pt="inputPt" />
                        <Message v-if="form.errors.name" severity="error" variant="simple" size="small">{{ form.errors.name }}</Message>
                    </div>

                    <!-- Key -->
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Key (Identificador único) *</label>
                        <InputText v-model="form.key" placeholder="Ej. module_pos" :pt="inputPt" class="font-mono" :disabled="isEdit" />
                        <Message v-if="form.errors.key" severity="error" variant="simple" size="small">{{ form.errors.key }}</Message>
                        <small v-if="isEdit" class="text-gray-500 text-xs">El key no se puede modificar una vez creado.</small>
                    </div>

                    <!-- Tipo -->
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Tipo de ítem *</label>
                        <Select v-model="form.type" :options="itemTypes" optionLabel="label" optionValue="value" placeholder="Seleccionar tipo" :pt="selectPt" />
                        <Message v-if="form.errors.type" severity="error" variant="simple" size="small">{{ form.errors.type }}</Message>
                    </div>

                    <!-- Metadatos (Condicional según Tipo) -->
                    <div v-if="form.type === 'module'" class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Ícono (PrimeIcons) *</label>
                        <div class="relative">
                            <span v-if="form.meta.icon" class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400">
                                <i :class="[form.meta.icon, '!text-sm']"></i>
                            </span>
                            <InputText v-model="form.meta.icon" placeholder="pi pi-shop" :pt="inputPt" :class="{ '!pl-10': form.meta.icon }" />
                        </div>
                        <Message v-if="form.errors['meta.icon']" severity="error" variant="simple" size="small">{{ form.errors['meta.icon'] }}</Message>
                    </div>

                    <div v-if="form.type === 'limit'" class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Cantidad (Límite base) *</label>
                        <InputNumber v-model="form.meta.quantity" placeholder="Ej. 100" class="w-full" :pt="inputNumberPt" />
                        <Message v-if="form.errors['meta.quantity']" severity="error" variant="simple" size="small">{{ form.errors['meta.quantity'] }}</Message>
                    </div>
                </div>

                <!-- Descripción -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Descripción técnica</label>
                    <Textarea v-model="form.description" rows="3" placeholder="Detalles operativos de este ítem..." :pt="textareaPt" />
                    <Message v-if="form.errors.description" severity="error" variant="simple" size="small">{{ form.errors.description }}</Message>
                </div>
            </div>
        </div>

        <!-- Columna Derecha: Precio, Estado y Acciones -->
        <div class="space-y-6">
            
            <!-- Panel de Precio -->
            <div class="bg-white dark:bg-[#1a1a1a] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                <h2 class="text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0 mb-6 flex items-center gap-2">
                    <i class="pi pi-dollar !text-sm text-gray-400"></i>
                    Facturación
                </h2>
                
                <div class="flex flex-col gap-1.5 mb-2">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Precio mensual *</label>
                    <InputNumber v-model="form.monthly_price" mode="currency" currency="MXN" locale="es-MX" class="w-full" :pt="telemetryNumberPt" />
                    <Message v-if="form.errors.monthly_price" severity="error" variant="simple" size="small">{{ form.errors.monthly_price }}</Message>
                </div>
                <p class="text-[10px] uppercase tracking-widest text-gray-500 m-0 mt-2">Valor en moneda base (MXN).</p>
            </div>

            <!-- Panel de Estado Operativo -->
            <div class="bg-white dark:bg-[#1a1a1a] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-medium text-gray-900 dark:text-white m-0">Estado operativo</h3>
                    <p class="text-[10px] uppercase tracking-widest text-gray-500 m-0 mt-1 flex items-center gap-2">
                        <span :class="['w-1.5 h-1.5 rounded-full', form.is_active ? 'bg-green-500 animate-pulse' : 'bg-gray-500']"></span>
                        {{ form.is_active ? 'Ítem disponible' : 'Ítem suspendido' }}
                    </p>
                </div>
                <ToggleSwitch v-model="form.is_active" :pt="switchPt" />
            </div>

            <!-- Acciones -->
            <div class="flex gap-3">
                <Button label="Cancelar" icon="pi pi-times" @click="cancel" severity="secondary" outlined class="w-1/3 !rounded-xl !text-xs !uppercase !tracking-wider" :disabled="form.processing" />
                <Button :label="isEdit ? 'Guardar cambios' : 'Crear ítem'" :icon="isEdit ? 'pi pi-save' : 'pi pi-check'" type="submit" severity="primary" class="w-2/3 !rounded-xl !text-xs !uppercase !tracking-wider" :loading="form.processing" />
            </div>
        </div>

    </form>
</template>