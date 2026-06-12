<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    visible: Boolean,
    type: {
        type: String, // 'ingreso' or 'egreso'
        required: true,
    },
    sessionId: {
        type: Number,
        required: true,
    }
});

const emit = defineEmits(['update:visible', 'submitted']);

const form = useForm({
    amount: null,
    description: '',
    type: props.type,
});

// Actualiza el tipo en el formulario si la prop cambia dinámicamente
watch(() => props.type, (newType) => {
    form.type = newType;
});

// Observamos cuando el modal se abre para forzar el tipo correcto y limpiar errores.
watch(() => props.visible, (isOpen) => {
    if (isOpen) {
        form.type = props.type;
        form.clearErrors();
    }
});

const modalTitle = computed(() => props.type === 'ingreso' ? 'Registrar ingreso de efectivo' : 'Registrar retiro de efectivo');
const amountLabel = computed(() => props.type === 'ingreso' ? 'Monto a ingresar' : 'Monto a retirar');

const closeModal = () => {
    emit('update:visible', false);
    form.reset(); 
};

const submit = () => {
    if (props.sessionId) {
        form.post(route('session-cash-movements.store', props.sessionId), {
            preserveScroll: true,
            onSuccess: () => {
                emit('submitted');
                closeModal();
            },
        });
    }
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal :header="modalTitle" 
        class="w-full max-w-lg"
        :breakpoints="{ '1199px': '75vw', '575px': '95vw' }"
        :pt="{
            root: { class: 'dark:bg-[#232323] border-none shadow-2xl rounded-3xl overflow-hidden' },
            header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-8 py-6' },
            title: { class: 'text-xl md:text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0' },
            content: { class: 'dark:bg-[#232323] px-8 py-6' }
        }">
        
        <form @submit.prevent="submit" class="space-y-6">
            
            <!-- Campo: Monto -->
            <div>
                <label :for="`movement-amount-${type}`" class="block text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 mb-2">
                    {{ amountLabel }} *
                </label>
                <InputNumber :id="`movement-amount-${type}`" v-model="form.amount" mode="currency" currency="MXN" locale="es-MX" 
                    class="w-full" 
                    :autofocus="true"
                    :pt="{ 
                        root: { class: 'w-full' },
                        input: { root: { class: 'w-full !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3 !text-3xl !font-light !text-gray-900 dark:!text-white' } } 
                    }" />
                
                <Message v-if="form.errors.amount" severity="error" variant="simple" size="small" class="mt-1">
                    {{ form.errors.amount }}
                </Message>
            </div>

            <!-- Campo: Descripción -->
             <div>
                <label :for="`movement-description-${type}`" class="block text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 mb-2">
                    Descripción / motivo *
                </label>
                <Textarea :id="`movement-description-${type}`" v-model="form.description" rows="3" 
                    class="w-full !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors resize-none !text-sm !py-3" 
                    placeholder="Escribe el motivo del movimiento..." />
                
                <Message v-if="form.errors.description" severity="error" variant="simple" size="small" class="mt-1">
                    {{ form.errors.description }}
                </Message>
            </div>

            <!-- Acciones -->
            <div class="flex items-center justify-end gap-3 pt-6 mt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal" text 
                    class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold" />
                
                <Button type="submit" label="Registrar movimiento" icon="pi pi-check" :loading="form.processing" 
                    class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-8 !py-3 shadow-[0_4px_10px_rgba(246,140,15,0.3)]" />
            </div>
        </form>
    </Dialog>
</template>