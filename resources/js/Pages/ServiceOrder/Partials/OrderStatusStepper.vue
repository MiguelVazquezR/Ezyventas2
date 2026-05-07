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
            message: `¿Estás seguro de que quieres avanzar el estatus a "${newStatusLabel}"?`,
            header: 'Confirmar avance',
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
            }
        }
    });
};

// Passthrough de PrimeVue Stepper para quitar el padding base
const stepperPt = {
    root: { class: 'w-full' }
};
const stepListPt = {
    root: { class: 'flex justify-between items-center w-full !bg-transparent !p-0 !border-none' }
};
const stepPt = {
    root: { class: 'flex-1 first:flex-initial last:flex-initial !bg-transparent !border-none !p-0' }
};
</script>

<template>
    <div class="col-span-full bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col w-full overflow-hidden">
        
        <!-- Header -->
        <div class="mb-8 flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                <i class="pi pi-sitemap !text-sm text-blue-500"></i>
            </div>
            <div>
                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Flujo de estatus</h2>
                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Seguimiento de la reparación</p>
            </div>
        </div>
        
        <!-- Estado Cancelado -->
        <div v-if="isCancelled" class="bg-red-50 dark:bg-red-900/10 p-6 rounded-2xl border border-red-100 dark:border-red-900/30 flex flex-col items-center justify-center text-center">
            <i class="pi pi-times-circle text-red-500 !text-4xl mb-3 drop-shadow-[0_0_8px_rgba(239,68,68,0.5)]"></i>
            <p class="font-bold text-red-700 dark:text-red-400 text-sm m-0 tracking-tight">Esta orden ha sido cancelada.</p>
            <p class="text-xs text-red-600 dark:text-red-500/80 mt-1 m-0">El proceso se ha detenido y el inventario ha sido liberado.</p>
        </div>
        
        <!-- Stepper Activo -->
        <div v-else class="w-full overflow-x-auto custom-scrollbar pb-2">
            <Stepper v-model:value="activeIndex" class="min-w-[600px]" :pt="stepperPt">
                <StepList :pt="stepListPt">
                    <Step v-for="(step, index) in steps" :key="step.label" :value="index + 1" v-slot="{ value }" asChild :pt="stepPt">
                        <div class="flex flex-row items-center" :class="index !== 4 ? 'w-full' : 'w-auto'">
                            
                            <!-- Botón del Paso -->
                            <button class="bg-transparent border-0 inline-flex flex-col gap-3 items-center justify-center focus:outline-none shrink-0"
                                @click="changeStatus(step.value, value)" :class="index === 4 ? 'w-24' : 'w-24'">
                                
                                <span :class="[
                                    'w-12 h-12 rounded-full border-2 flex items-center justify-center transition-all duration-300 relative z-10', 
                                    { 
                                        'bg-blue-500 border-blue-500 text-white shadow-[0_0_6px_rgba(59,130,246,0.6)] scale-110': value === activeIndex,
                                        'bg-blue-500 border-blue-500 text-white': value < activeIndex,
                                        'bg-gray-50 dark:bg-[#1a1a1a] border-gray-200 dark:border-[#3a3a3a] text-gray-400': value > activeIndex, 
                                        'cursor-pointer hover:border-blue-400 hover:text-blue-500 dark:hover:border-blue-500': (value > activeIndex && hasPermission('services.orders.change_status')) || (value < activeIndex && hasPermission('services.orders.edit')),
                                        'cursor-not-allowed': (!hasPermission('services.orders.change_status') && value > activeIndex) || (!hasPermission('services.orders.edit') && value < activeIndex)
                                    }
                                ]">
                                    <i :class="step.icon" class="!text-lg" />
                                </span>
                                
                                <span :class="[
                                    'text-[10px] uppercase tracking-widest text-center leading-tight m-0', 
                                    { 
                                        'text-blue-600 dark:text-blue-400 font-bold': value <= activeIndex, 
                                        'text-gray-500 font-medium': value > activeIndex 
                                    }
                                ]">
                                    {{ step.label }}
                                </span>
                            </button>

                            <!-- Línea Conectora (Barra de progreso) -->
                            <div v-if="index !== 4" 
                                 class="h-1 flex-grow rounded-full mx-2 transition-all duration-500 relative -top-3"
                                 :class="value < activeIndex ? 'bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.5)]' : 'bg-gray-100 dark:bg-[#3a3a3a]'">
                            </div>
                        </div>
                    </Step>
                </StepList>
            </Stepper>
        </div>
    </div>
</template>