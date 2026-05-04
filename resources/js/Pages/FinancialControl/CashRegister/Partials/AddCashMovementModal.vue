<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    visible: Boolean,
    session: Object,
    movementToEdit: {
        type: Object,
        default: null
    }
});
const emit = defineEmits(['update:visible']);

const form = useForm({
    type: 'ingreso',
    amount: null,
    description: '',
});

// Computed properties para saber el modo y título
const isEditing = computed(() => !!props.movementToEdit);
const modalTitle = computed(() => isEditing.value ? 'Editar movimiento' : 'Registrar movimiento');

// Observador para llenar el formulario cuando se abre el modal
watch(() => props.visible, (isVisible) => {
    if (isVisible) {
        if (props.movementToEdit) {
            form.type = props.movementToEdit.type;
            form.amount = parseFloat(props.movementToEdit.amount);
            form.description = props.movementToEdit.description;
        } else {
            form.reset();
            form.clearErrors();
        }
    } else {
        form.reset();
        form.clearErrors();
    }
});

const typeOptions = [ 
    { label: 'Ingreso (Entrada de dinero)', value: 'ingreso' }, 
    { label: 'Egreso (Retiro o pago)', value: 'egreso' } 
];

const closeModal = () => { 
    emit('update:visible', false); 
};

const submit = () => {
    if (isEditing.value) {
        // Enviar por PUT a la ruta de actualización
        form.put(route('session-cash-movements.update', props.movementToEdit.id), {
            onSuccess: () => closeModal(),
            preserveScroll: true,
        });
    } else {
        // Enviar por POST para crear uno nuevo
        form.post(route('session-cash-movements.store', props.session.id), {
            onSuccess: () => closeModal(),
            preserveScroll: true,
        });
    }
};

// --- TESLA UI PASS-THROUGH (PT) ---
const dialogPt = {
    root: { class: 'dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] rounded-3xl shadow-2xl overflow-hidden' },
    header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-5' },
    title: { class: 'text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0' },
    content: { class: 'dark:bg-[#232323] p-6 lg:p-8' },
    closeButton: { class: 'hover:bg-gray-100 dark:hover:bg-[#1a1a1a] transition-colors rounded-full w-8 h-8 flex items-center justify-center' },
    closeButtonIcon: { class: 'dark:text-gray-400 !text-sm' },
};

const selectPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors w-full' },
    label: { class: '!text-sm !py-2.5' },
    panel: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-xl' }
};

const inputPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2.5 !text-sm w-full font-mono' }
};

const textareaPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3 !text-sm w-full' }
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal class="w-full max-w-md mx-4" :pt="dialogPt">
        
        <!-- Custom Header -->
        <template #header>
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 border transition-colors duration-300"
                    :class="form.type === 'ingreso' ? 'bg-green-50 dark:bg-green-900/20 border-green-100 dark:border-green-900/30 text-green-500' : 'bg-red-50 dark:bg-red-900/20 border-red-100 dark:border-red-900/30 text-red-500'">
                    <i class="pi !text-sm" :class="form.type === 'ingreso' ? 'pi-arrow-down-left' : 'pi-arrow-up-right'"></i>
                </div>
                <div>
                    <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">{{ modalTitle }}</h2>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">
                        Operación manual de caja
                    </p>
                </div>
            </div>
        </template>

        <form @submit.prevent="submit" class="space-y-5">
            <!-- Tipo de Movimiento -->
            <div>
                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 block mb-2">
                    Tipo de Movimiento *
                </span>
                <Select id="type" v-model="form.type" :options="typeOptions" optionLabel="label" optionValue="value" :pt="selectPt" />
            </div>
            
            <!-- Monto -->
            <div>
                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 block mb-2">
                    Monto *
                </span>
                <InputNumber id="amount" v-model="form.amount" mode="currency" currency="MXN" locale="es-MX" :min="0.01" :pt="{ input: inputPt }" />
                <InputError :message="form.errors.amount" class="mt-2" />
            </div>
            
            <!-- Descripción -->
            <div>
                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 block mb-2">
                    Descripción / Razón *
                </span>
                <Textarea id="description" v-model="form.description" rows="3" placeholder="Ej: Pago a proveedor, cambio, etc." :pt="textareaPt" />
                <InputError :message="form.errors.description" class="mt-2" />
            </div>
            
            <!-- Acciones -->
            <div class="flex justify-end gap-3 mt-8 pt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
                <Button type="button" label="Cancelar" text @click="closeModal" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold" />
                <Button type="submit" :label="isEditing ? 'Guardar Cambios' : 'Registrar'" :loading="form.processing" 
                    class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-6" 
                    :severity="form.type === 'ingreso' ? 'success' : 'danger'" />
            </div>
        </form>
    </Dialog>
</template>