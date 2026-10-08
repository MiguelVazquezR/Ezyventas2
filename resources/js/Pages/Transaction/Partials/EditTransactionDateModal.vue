<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    visible: Boolean,
    transactionId: {
        type: Number,
        required: true
    },
    currentDate: {
        type: String,
        default: null
    },
    // Fecha de vencimiento actual (apartado o crédito). Null si la venta no tiene.
    expirationDate: {
        type: String,
        default: null
    },
    // Estatus de la transacción, para nombrar correctamente el vencimiento.
    status: {
        type: String,
        default: null
    }
});

const emit = defineEmits(['update:visible', 'success']);

const isVisible = computed({
    get: () => props.visible,
    set: (value) => emit('update:visible', value)
});

// Plazo por defecto con el que se crean apartados y créditos en el POS.
const DEFAULT_EXPIRATION_DAYS = 30;

const newDate = ref(null);
const isProcessing = ref(false);

// Qué hacer con el vencimiento al cambiar la fecha de la venta:
//   'shift' → moverlo a la nueva fecha + 30 días (recomendado).
//   'keep'  → conservar la fecha de vencimiento guardada.
const expirationAction = ref('shift');

const hasExpiration = computed(() => !!props.expirationDate);

const currentExpirationDate = computed(() => props.expirationDate ? new Date(props.expirationDate) : null);

// Mismo criterio que el panel de información: pendiente = crédito, lo demás = apartado.
const expirationLabel = computed(() => {
    if (props.status === 'pendiente') return 'Vencimiento del crédito';
    return 'Vencimiento del apartado';
});

// Vencimiento sugerido cuando se mueve: nueva fecha de venta + 30 días.
const shiftedExpirationDate = computed(() => {
    if (!newDate.value) return null;
    const shifted = new Date(newDate.value);
    shifted.setDate(shifted.getDate() + DEFAULT_EXPIRATION_DAYS);
    return shifted;
});

// Si se conserva el vencimiento pero la nueva fecha queda después de él, se avisa.
const isExpirationBeforeNewDate = computed(() => {
    if (!newDate.value || !currentExpirationDate.value) return false;
    const saleDate = new Date(newDate.value);
    const expiration = new Date(currentExpirationDate.value);
    saleDate.setHours(0, 0, 0, 0);
    expiration.setHours(0, 0, 0, 0);
    return saleDate > expiration;
});

watch(() => props.visible, (newVal) => {
    if (newVal) {
        newDate.value = props.currentDate ? new Date(props.currentDate) : new Date();
        expirationAction.value = 'shift';
    }
});

const formatDateOnly = (date) => date ? date.toLocaleDateString('es-MX', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '';

const toLocalISOString = (date) => {
    if (!date) return null;
    const tzOffset = date.getTimezoneOffset() * 60000;
    return (new Date(date - tzOffset)).toISOString().slice(0, 19).replace('T', ' ');
};

const submit = () => {
    if (!newDate.value) return;

    const payload = { created_at: toLocalISOString(newDate.value) };

    // Si la venta tiene vencimiento y el usuario eligió moverlo, se envía la nueva fecha.
    if (hasExpiration.value && expirationAction.value === 'shift' && shiftedExpirationDate.value) {
        payload.new_expiration_date = toLocalISOString(shiftedExpirationDate.value);
    }

    isProcessing.value = true;
    router.put(route('transactions.update-date', props.transactionId), payload, {
        onSuccess: () => {
            isVisible.value = false;
            emit('success');
        },
        onFinish: () => isProcessing.value = false
    });
};
</script>

<template>
    <Dialog v-model:visible="isVisible" modal header="Editar fecha de venta" :style="{ width: '28rem' }">
        <div class="flex flex-col gap-4 py-2">
            <Message severity="warn" :closable="false">
                Cambiar la fecha afectará los reportes y cortes de caja de ese día.
            </Message>
            <div class="flex flex-col gap-2">
                <label class="font-bold text-gray-700 dark:text-gray-300">Nueva fecha y hora</label>
                <DatePicker v-model="newDate" showTime hourFormat="12" dateFormat="dd/mm/yy" showIcon class="w-full" />
            </div>

            <!-- Vencimiento: solo si la venta es apartado o crédito -->
            <div v-if="hasExpiration" class="border-t border-gray-100 dark:border-[#3a3a3a] pt-4 flex flex-col gap-3">
                <div class="flex flex-col gap-1">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">{{ expirationLabel }} actual</span>
                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100 m-0">
                        {{ currentExpirationDate ? formatDateOnly(currentExpirationDate) : '—' }}
                    </span>
                </div>

                <p class="text-sm text-gray-700 dark:text-gray-300 m-0">¿Qué hacemos con el vencimiento?</p>

                <!-- Opción recomendada: mover el vencimiento con la nueva fecha -->
                <label class="flex items-start gap-3 p-3 rounded-2xl border cursor-pointer transition-colors select-none"
                       :class="expirationAction === 'shift'
                           ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20'
                           : 'border-gray-200 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#1a1a1a] hover:border-gray-300 dark:hover:border-gray-600'"
                       @click="expirationAction = 'shift'">
                    <RadioButton v-model="expirationAction" inputId="expiration_shift" value="shift" />
                    <span class="flex flex-col gap-1 min-w-0">
                        <span class="text-sm font-semibold text-gray-900 dark:text-white m-0">Moverlo a la nueva fecha</span>
                        <span class="text-xs text-gray-500 m-0 leading-relaxed">
                            El vencimiento pasaría al {{ shiftedExpirationDate ? formatDateOnly(shiftedExpirationDate) : '—' }} (nueva fecha + 30 días)
                        </span>
                    </span>
                </label>

                <!-- Opción: conservar la fecha guardada -->
                <label class="flex items-start gap-3 p-3 rounded-2xl border cursor-pointer transition-colors select-none"
                       :class="expirationAction === 'keep'
                           ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20'
                           : 'border-gray-200 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#1a1a1a] hover:border-gray-300 dark:hover:border-gray-600'"
                       @click="expirationAction = 'keep'">
                    <RadioButton v-model="expirationAction" inputId="expiration_keep" value="keep" />
                    <span class="flex flex-col gap-1 min-w-0">
                        <span class="text-sm font-semibold text-gray-900 dark:text-white m-0">Conservar el vencimiento actual</span>
                        <span class="text-xs text-gray-500 m-0 leading-relaxed">
                            Se mantiene en {{ currentExpirationDate ? formatDateOnly(currentExpirationDate) : '—' }}
                        </span>
                    </span>
                </label>

                <!-- Aviso si al conservar el vencimiento quedaría antes que la nueva fecha -->
                <Message v-if="expirationAction === 'keep' && isExpirationBeforeNewDate" severity="warn" variant="simple" size="small" :closable="false">
                    La nueva fecha de venta queda después del vencimiento actual. Te recomendamos mover el vencimiento.
                </Message>
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <Button label="Cancelar" severity="secondary" text @click="isVisible = false" />
                <Button label="Actualizar" icon="pi pi-save" @click="submit" :loading="isProcessing" />
            </div>
        </template>
    </Dialog>
</template>