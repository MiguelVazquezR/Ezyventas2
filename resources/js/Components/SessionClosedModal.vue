<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    visible: Boolean,
    eventData: Object, // { closingUserName, cashRegisterId, originalOpenerId }
});

const emit = defineEmits(['update:visible']);

const isLoading = ref(false);

/**
 * Llama a la nueva ruta del backend para unirse o crear una sesión.
 */
const rejoin = () => {
    if (!props.eventData) return;

    isLoading.value = true;
    router.post(route('cash-register-sessions.rejoinOrStart'), {
        cash_register_id: props.eventData.cashRegisterId,
        original_opener_id: props.eventData.originalOpenerId,
    }, {
        onFinish: () => {
            isLoading.value = false;
            closeModal();
        },
        preserveScroll: true,
    });
};

const closeModal = () => {
    emit('update:visible', false);
};
</script>

<template>
    <!-- 
      Forzamos al usuario a interactuar con el modal.
      No se puede cerrar haciendo clic fuera o con la tecla Esc.
    -->
    <Dialog 
        :visible="visible" 
        @update:visible="closeModal" 
        modal 
        header="Sesión de caja cerrada" 
        :style="{ width: '30rem' }"
        :closable="false"
        :draggable="false"
    >
        <div class="p-4 text-center">
            <div class="flex items-center justify-center mx-auto mb-6 h-20 w-20 rounded-full bg-red-100 dark:bg-red-900/50">
                <i class="pi pi-power-off !text-4xl text-red-500"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200">¡Sesión terminada!</h2>
            <p class="text-gray-600 dark:text-gray-400 mt-2">
                El usuario 
                <strong class="font-semibold text-gray-900 dark:text-gray-100">{{ eventData?.closingUserName || 'un administrador' }}</strong> 
                ha realizado el corte de caja.
            </p>
            <p class="text-gray-600 dark:text-gray-400 mt-1">
                Has sido desconectado de esta sesión.
            </p>
        </div>

        <template #footer>
            <div class="flex flex-col w-full gap-2">
                <!-- Botón de acción principal -->
                <Button 
                    label="Unirse a una nueva sesión" 
                    icon="pi pi-sign-in" 
                    @click="rejoin" 
                    :loading="isLoading" 
                    class="w-full"
                />
                <!-- Botón secundario para solo cerrar el modal -->
                 <Button 
                    label="Entendido" 
                    severity="secondary" 
                    text 
                    @click="closeModal" 
                    class="w-full"
                />
            </div>
        </template>
    </Dialog>
</template>