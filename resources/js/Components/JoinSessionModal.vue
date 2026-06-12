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
    <Dialog :visible="visible" @update:visible="closeModal" :modal="true" header="Unirse a una sesión activa" 
        class="w-full max-w-xl"
        :breakpoints="{ '1199px': '75vw', '575px': '95vw' }"
        :closable="true"
        :pt="{
            root: { class: 'dark:bg-[#232323] border-none shadow-2xl rounded-3xl overflow-hidden' },
            header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-8 py-6' },
            title: { class: 'text-xl md:text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0' },
            content: { class: 'dark:bg-[#232323] px-8 py-6' },
            footer: { class: 'dark:bg-[#232323] border-t border-gray-100 dark:border-[#3a3a3a] px-8 py-4' }
        }">
        
        <div v-if="sessions && sessions.length > 0">
            <!-- Encabezado de la acción -->
            <div class="flex items-center gap-4 mb-8 bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                <div class="w-12 h-12 rounded-full bg-green-50 dark:bg-green-900/30 flex items-center justify-center text-green-500 shadow-sm border border-green-100 dark:border-green-900/50">
                    <i class="pi pi-users !text-xl"></i>
                </div>
                <div>
                    <h2 class="font-medium text-lg text-gray-900 dark:text-gray-100 tracking-tight m-0">Sesiones activas</h2>
                    <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1">Selecciona una caja para operar</p>
                </div>
            </div>

            <div class="space-y-3 max-h-[50vh] overflow-y-auto pr-2 custom-scrollbar">
                 <div v-for="session in sessions" :key="session.id" 
                    class="flex items-center justify-between p-4 bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] rounded-2xl hover:border-green-500/50 dark:hover:border-green-500/50 transition-colors group">
                    <div class="flex items-center gap-3">
                         <div class="w-8 h-8 rounded-full bg-gray-50 dark:bg-[#1a1a1a] flex items-center justify-center group-hover:bg-green-50 dark:group-hover:bg-green-900/20 transition-colors">
                            <span class="w-2 h-2 rounded-full bg-green-500 shadow-[0_0_5px_rgba(34,197,94,0.5)]"></span>
                        </div>
                        <div>
                            <p class="font-medium text-sm text-gray-900 dark:text-white m-0">{{ session.cash_register.name }}</p>
                            <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-0.5">Operador: {{ session.opener.name }}</p>
                        </div>
                    </div>
                    <Button 
                        label="Unirme" 
                        icon="pi pi-sign-in" 
                        @click="joinSession(session.id)"
                        :loading="loadingSessionId === session.id"
                        severity="secondary"
                        class="!rounded-xl !uppercase !tracking-widest !text-[10px] !font-bold"
                    />
                </div>
            </div>
        </div>
        
        <!-- Estado Vacío -->
        <div v-else class="py-12 flex flex-col items-center justify-center text-center">
            <div class="w-16 h-16 bg-gray-50 dark:bg-[#1a1a1a] rounded-full flex items-center justify-center mb-6 border border-gray-100 dark:border-[#3a3a3a]">
                <i class="pi pi-info-circle !text-2xl text-blue-500"></i>
            </div>
            <h2 class="text-xl font-light text-gray-900 dark:text-white tracking-tight m-0 mb-2">No hay sesiones activas</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 max-w-xs leading-relaxed">
                Actualmente no hay ninguna caja operando en esta sucursal a la que puedas unirte.
            </p>
        </div>
        
        <template #footer>
            <div class="flex justify-end">
                <Button label="Cancelar" text severity="secondary" @click="closeModal" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold" />
            </div>
        </template>
    </Dialog>
</template>