<script setup>
import AppLogo from '@/Components/AuthenticationCardLogo.vue';

defineProps({
    visible: Boolean,
});

const emit = defineEmits(['update:visible']);

// --- Tesla UI Dialog PT ---
const dialogPt = {
    root: { class: 'dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] rounded-3xl shadow-2xl overflow-hidden' },
    header: { class: 'bg-gray-50 dark:bg-[#1a1a1a] border-b border-gray-100 dark:border-[#3a3a3a] px-5 py-4' },
    content: { class: 'p-0 dark:bg-[#232323]' },
};
</script>

<template>
    <Dialog
        :visible="visible"
        @update:visible="emit('update:visible', $event)"
        modal
        :style="{ width: '28rem' }"
        :pt="dialogPt"
    >
        <template #header>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-primary-500/10 flex items-center justify-center">
                    <i class="pi pi-headphones text-primary-500 !text-lg"></i>
                </div>
                <div>
                    <h3 class="text-base font-medium text-gray-900 dark:text-white m-0">Centro de soporte</h3>
                    <p class="text-xs uppercase tracking-widest text-gray-500 m-0">Estamos aquí para ayudarte</p>
                </div>
            </div>
        </template>

        <div class="flex flex-col items-center gap-5 px-6 py-6">
            <!-- Logo -->
            <AppLogo class="h-9 w-auto opacity-80" />

            <!-- Mensaje informativo -->
            <p class="text-base text-gray-600 dark:text-gray-400 text-center leading-relaxed m-0">
                Puedes solicitar soporte técnico, reportar algún error, sugerir mejoras al sistema o proponer nuevas funcionalidades. Con gusto lo evaluaremos.
            </p>

            <!-- Horario -->
            <div class="bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a] p-4 w-full">
                <div class="flex items-center gap-2 mb-2">
                    <i class="pi pi-clock text-gray-400 !text-sm"></i>
                    <span class="text-xs uppercase tracking-widest font-bold text-gray-500">Horario de atención</span>
                </div>
                <div class="space-y-1">
                    <p class="text-sm text-gray-700 dark:text-gray-300 m-0 flex justify-between">
                        <span>Lunes a viernes</span>
                        <span class="font-mono font-medium">8:00 AM — 7:00 PM</span>
                    </p>
                    <p class="text-sm text-gray-700 dark:text-gray-300 m-0 flex justify-between">
                        <span>Sábados</span>
                        <span class="font-mono font-medium">9:00 AM — 3:00 PM</span>
                    </p>
                </div>
            </div>

            <!-- Canales de contacto -->
            <div class="w-full space-y-3">
                <!-- Email -->
                <a
                    href="mailto:notificaciones@ezyventas.com"
                    class="flex items-center gap-3 p-4 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#1a1a1a] hover:bg-blue-50 dark:hover:bg-blue-500/10 transition-colors no-underline group"
                >
                    <div class="w-10 h-10 rounded-xl bg-blue-500/10 flex items-center justify-center flex-shrink-0 group-hover:bg-blue-500/20 transition-colors">
                        <i class="pi pi-envelope text-blue-500 !text-lg"></i>
                    </div>
                    <div class="flex flex-col min-w-0">
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Correo electrónico</span>
                        <span class="text-sm text-blue-600 dark:text-blue-400 font-medium truncate">notificaciones@ezyventas.com</span>
                    </div>
                    <i class="pi pi-arrow-up-right !text-xs text-gray-400 ml-auto flex-shrink-0"></i>
                </a>

                <!-- WhatsApp -->
                <a
                    href="https://wa.me/5213321705650"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="flex items-center gap-3 p-4 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] bg-gray-50 dark:bg-[#1a1a1a] hover:bg-green-50 dark:hover:bg-green-500/10 transition-colors no-underline group"
                >
                    <div class="w-10 h-10 rounded-xl bg-green-500/10 flex items-center justify-center flex-shrink-0 group-hover:bg-green-500/20 transition-colors">
                        <i class="pi pi-whatsapp text-green-500 !text-lg"></i>
                    </div>
                    <div class="flex flex-col min-w-0">
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">WhatsApp</span>
                        <span class="text-sm text-green-600 dark:text-green-400 font-medium">+52 33 2170 5650</span>
                    </div>
                    <i class="pi pi-arrow-up-right !text-xs text-gray-400 ml-auto flex-shrink-0"></i>
                </a>
            </div>
        </div>
    </Dialog>
</template>
