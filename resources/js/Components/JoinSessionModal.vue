<script setup>
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    visible: Boolean,
    sessions: Array,
});

const emit = defineEmits(['update:visible']);
const loadingSessionId = ref(null);

const joinSession = (sessionId) => {
    loadingSessionId.value = sessionId;
    router.post(route('cash-register-sessions.join', sessionId), {}, {
        onFinish: () => {
            closeModal();
            loadingSessionId.value = null;
        }
    });
};

const closeModal = () => {
    emit('update:visible', false);
};
</script>

<template>
    <!-- CORRECCIÓN: Se cambia :closable a true y se añade un footer con botón para cancelar -->
    <Dialog :visible="visible" @update:visible="closeModal" :modal="true" header="Unirse a una sesión de caja" :style="{ width: '35rem' }" :closable="true">
        <div class="p-4 text-center">
            <div class="bg-green-100 dark:bg-green-900/50 rounded-full h-20 w-20 flex items-center justify-center mx-auto mb-6">
                <i class="pi pi-users !text-4xl text-green-500"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200">Sesiones Activas</h2>
            <p class="text-gray-600 dark:text-gray-400 mt-2">
                Selecciona una de las siguientes sesiones de caja para unirte y comenzar a vender.
            </p>
        </div>

        <div v-if="sessions && sessions.length > 0" class="p-2 space-y-3">
             <div v-for="session in sessions" :key="session.id" class="flex items-center justify-between p-3 border rounded-lg dark:border-gray-700">
                <div>
                    <p class="font-bold text-lg">{{ session.cash_register.name }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Abierta por: <span class="font-semibold">{{ session.opener.name }}</span></p>
                </div>
                <Button 
                    label="Unirse" 
                    icon="pi pi-sign-in" 
                    @click="joinSession(session.id)"
                    :loading="loadingSessionId === session.id"
                />
            </div>
        </div>
        <div v-else class="p-4">
            <Message severity="info" :closable="false">No hay sesiones activas a las que te puedas unir en este momento.</Message>
        </div>
        
        <template #footer>
            <Button label="Cancelar" text severity="secondary" @click="closeModal" />
        </template>
    </Dialog>
</template>