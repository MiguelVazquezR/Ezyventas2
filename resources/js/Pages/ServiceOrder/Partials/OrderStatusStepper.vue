<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { usePermissions } from '@/Composables';

const props = defineProps({
    serviceOrder: {
        type: Object,
        required: true
    },
    amountDue: {
        type: Number,
        required: true
    }
});

const emit = defineEmits(['requirePayment']);
const confirm = useConfirm();
const toast = useToast();
const { hasPermission } = usePermissions();

const steps = [
    { label: 'Pendiente', value: 'pendiente', icon: 'pi pi-inbox' },
    { label: 'En Progreso', value: 'en_progreso', icon: 'pi pi-cog' },
    { label: 'Esperando refacción', value: 'esperando_refaccion', icon: 'pi pi-pause' },
    { label: 'Terminado', value: 'terminado', icon: 'pi pi-check-circle' },
    { label: 'Entregado', value: 'entregado', icon: 'pi pi-box' }
];

const activeIndex = computed(() => {
    const index = steps.findIndex(step => step.value === props.serviceOrder.status);
    return index >= 0 ? index + 1 : 0;
});

const isCancelled = computed(() => props.serviceOrder.status === 'cancelado');

const changeStatus = (targetStatusValue, targetIndexValue) => {
    if (isCancelled.value) return;

    const currentIndexValue = activeIndex.value;

    // Regresar a un estatus anterior
    if (targetIndexValue < currentIndexValue && hasPermission('services.orders.edit')) {
        confirm.require({
            message: '¿Estás seguro de que quieres regresar la orden a esta etapa? Esto podría anular el progreso de las etapas posteriores.',
            header: 'Regresar Estatus',
            icon: 'pi pi-exclamation-triangle',
            acceptClass: 'p-button-warning',
            accept: () => executeStatusChange(targetStatusValue, false)
        });
        return;
    }

    // Avanzar a un estatus posterior
    if (targetIndexValue > currentIndexValue && hasPermission('services.orders.change_status')) {
        const newStatusLabel = steps.find(s => s.value === targetStatusValue)?.label || targetStatusValue;
        confirm.require({
            message: `¿Estás seguro de que quieres cambiar el estatus a "${newStatusLabel}"?`,
            header: 'Confirmar Cambio de Estatus',
            icon: 'pi pi-sync',
            accept: () => executeStatusChange(targetStatusValue, true)
        });
        return;
    }
};

const executeStatusChange = (newStatus, isForward) => {
    router.patch(route('service-orders.updateStatus', props.serviceOrder.id), { status: newStatus }, {
        preserveScroll: true,
        onSuccess: () => {
            if (isForward && newStatus === 'entregado' && props.amountDue > 0.01) {
                emit('requirePayment');
            } else if (!isForward) {
            }
        }
    });
};
</script>

<template>
    <div class="col-span-full bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
        <h2 class="text-lg font-semibold border-b pb-3 mb-6">Flujo de estatus</h2>
        
        <div v-if="isCancelled" class="text-center p-4 bg-red-50 dark:bg-red-900/20 rounded-md">
            <i class="pi pi-times-circle text-red-500 !text-3xl"></i>
            <p class="mt-1 font-semibold text-red-700 dark:text-red-300">Esta orden ha sido cancelada.</p>
        </div>
        
        <Stepper v-else v-model:value="activeIndex" class="basis-full">
            <StepList>
                <Step v-for="(step, index) in steps" :key="step.label" :value="index + 1" v-slot="{ value }" asChild>
                    <div class="flex flex-row flex-auto">
                        <button class="bg-transparent border-0 inline-flex flex-col gap-2 items-center focus:outline-none"
                            :class="index == 4 ? 'w-32' : 'w-60'" 
                            @click="changeStatus(step.value, value)">
                            
                            <span :class="[
                                'size-12 rounded-full border-2 flex items-center justify-center transition-colors duration-200', 
                                { 
                                    'bg-primary border-primary text-primary-contrast': value <= activeIndex, 
                                    'border-surface-200 dark:border-surface-700': value > activeIndex, 
                                    'cursor-pointer hover:border-primary': (value > activeIndex && hasPermission('services.orders.change_status')) || (value < activeIndex && hasPermission('services.orders.edit'))
                                }
                            ]">
                                <i :class="step.icon" />
                            </span>
                            <span :class="['font-medium text-xs', { 'text-primary': value <= activeIndex }]">
                                {{ step.label }}
                            </span>
                        </button>
                        <Divider v-if="index != 4" />
                    </div>
                </Step>
            </StepList>
        </Stepper>
    </div>
</template>