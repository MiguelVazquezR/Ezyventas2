<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';

defineProps({
    sessions: Array,
});

const confirmingLogout = ref(false);
const passwordInput = ref(null);

const form = useForm({
    password: '',
});

const confirmLogout = () => {
    confirmingLogout.value = true;
    // setTimeout(() => passwordInput.value.focus(), 250);
};

const logoutOtherBrowserSessions = () => {
    form.delete(route('other-browser-sessions.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => passwordInput.value.focus(),
        onFinish: () => form.reset(),
    });
};

const closeModal = () => {
    confirmingLogout.value = false;
    form.reset();
};
</script>

<template>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="md:col-span-1">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Sesiones del Navegador</h3>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Gestiona y cierra las sesiones activas en otros navegadores y dispositivos.
            </p>
        </div>

        <div class="md:col-span-2">
            <div class="p-6 bg-white dark:bg-gray-800 sm:rounded-lg">
                <div class="max-w-xl text-sm text-gray-600 dark:text-gray-400">
                    Si es necesario, puedes cerrar todas las demás sesiones de tu navegador en todos tus dispositivos.
                </div>

                <div v-if="sessions.length > 0" class="mt-5 space-y-6">
                    <div v-for="(session, i) in sessions" :key="i" class="flex items-center">
                        <div>
                            <i v-if="session.agent.is_desktop" class="pi pi-desktop text-2xl text-gray-500"></i>
                            <i v-else class="pi pi-mobile text-2xl text-gray-500"></i>
                        </div>
                        <div class="ml-3">
                            <div class="text-sm text-gray-600 dark:text-gray-400">
                                {{ session.agent.platform ? session.agent.platform : 'Desconocido' }} - {{ session.agent.browser ? session.agent.browser : 'Desconocido' }}
                            </div>
                            <div>
                                <div class="text-xs text-gray-500">
                                    {{ session.ip_address }},
                                    <span v-if="session.is_current_device" class="text-green-500 font-semibold">Este dispositivo</span>
                                    <span v-else>Última vez activo: {{ session.last_active }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex items-center mt-5">
                    <Button @click="confirmLogout" label="Cerrar otras sesiones" />
                </div>
            </div>
        </div>
    </div>
    
    <Dialog v-model:visible="confirmingLogout" modal header="Cerrar Otras Sesiones" :style="{ width: '30rem' }" @hide="closeModal">
        <p class="mb-4 text-gray-600 dark:text-gray-400">
            Por favor, introduce tu contraseña para confirmar que deseas cerrar las sesiones en todos tus otros dispositivos.
        </p>
        <div>
            <Password fluid ref="passwordInput" v-model="form.password" @keyup.enter="logoutOtherBrowserSessions" placeholder="Contraseña" toggleMask :feedback="false" />
            <InputError :message="form.errors.password" class="mt-2" />
        </div>
        <template #footer>
            <Button label="Cancelar" @click="closeModal" text severity="secondary" />
            <Button label="Confirmar Cierre" @click="logoutOtherBrowserSessions" :loading="form.processing" />
        </template>
    </Dialog>
</template>