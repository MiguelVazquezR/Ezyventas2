<script setup>
import { ref, nextTick, watch, onMounted, onUnmounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useAiChat } from '@/composables/useAiChat';
import ProgressSpinner from 'primevue/progressspinner';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Drawer from 'primevue/drawer';
import Divider from 'primevue/divider';
import { useToast } from 'primevue/usetoast';

const props = defineProps({
    /** Control the drawer visibility from parent. */
    visible: Boolean,
});

const emit = defineEmits(['update:visible']);

const page = usePage();
const toast = useToast();
const { messages, isThinking, sendMessage, reset } = useAiChat();

const inputText = ref('');
const messagesContainer = ref(null);

/** Check if the user has the ai_agent.access permission. */
const canAccess = () => {
    const permissions = page.props.auth?.user?.permissions ?? [];
    return permissions.includes('ai_agent.access');
};

/** Auto-scroll when new messages arrive. */
watch(
    () => messages.value.length,
    async () => {
        await nextTick();
        if (messagesContainer.value) {
            messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
        }
    }
);

/** Clear state when drawer closes. */
watch(
    () => props.visible,
    (v) => {
        if (!v) reset();
    }
);

async function handleSend() {
    const text = inputText.value.trim();
    if (!text || isThinking.value) return;

    inputText.value = '';

    if (!canAccess()) {
        toast.add({
            severity: 'warn',
            summary: 'Sin acceso',
            detail: 'No tienes permiso para usar el asistente de IA.',
            life: 5000,
        });
        return;
    }

    await sendMessage(text);
}

function onKeydown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        handleSend();
    }
}

/** Simple markdown-to-html for links in tool results. */
function renderContent(text) {
    if (!text) return '';
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/\n/g, '<br>')
        .replace(
            /(https?:\/\/[^\s<]+)/g,
            '<a href="$1" target="_blank" class="text-primary-500 underline">$1</a>'
        );
}
</script>

<template>
    <Drawer
        :visible="visible"
        position="right"
        :style="{ width: '420px' }"
        :pt="{
            root: { class: '!bg-white dark:!bg-[#232323] !rounded-l-3xl !shadow-2xl' },
            header: { class: '!bg-white dark:!bg-[#232323] !border-b !border-gray-100 dark:!border-[#3a3a3a]' },
            content: { class: '!bg-white dark:!bg-[#232323] !p-0' },
        }"
        @update:visible="emit('update:visible', $event)"
    >
        <template #header>
            <div class="flex items-center gap-3 w-full">
                <div
                    class="w-9 h-9 rounded-full bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center flex-shrink-0"
                >
                    <i class="pi pi-sparkles !text-white !text-sm" />
                </div>
                <div>
                    <h3 class="m-0 text-sm font-semibold text-gray-900 dark:text-white">
                        Asistente IA
                    </h3>
                    <p class="m-0 text-[10px] uppercase tracking-widest font-bold text-gray-500">
                        EzyVentas AI
                    </p>
                </div>
            </div>
        </template>

        <!-- Messages area -->
        <div
            ref="messagesContainer"
            class="flex flex-col gap-3 p-4 overflow-y-auto"
            :style="{ height: 'calc(100vh - 12rem)' }"
        >
            <!-- Empty state -->
            <div
                v-if="messages.length === 0 && !isThinking"
                class="flex flex-col items-center justify-center h-full text-center px-4"
            >
                <div
                    class="w-16 h-16 rounded-full bg-gray-50 dark:bg-[#1a1a1a] flex items-center justify-center mb-4"
                >
                    <i class="pi pi-sparkles !text-2xl !text-primary-500" />
                </div>
                <p class="text-sm font-semibold text-gray-700 dark:text-gray-200 m-0 mb-1">
                    ¿En qué puedo ayudarte?
                </p>
                <p class="text-xs text-gray-500 dark:text-gray-400 m-0">
                    Pregúntame sobre ventas, inventario, clientes o pídeme que genere reportes.
                </p>
            </div>

            <!-- Messages -->
            <template v-for="(msg, i) in messages" :key="i">
                <!-- User bubble -->
                <div v-if="msg.role === 'user'" class="flex justify-end">
                    <div
                        class="max-w-[80%] rounded-2xl rounded-br-md px-4 py-2.5 bg-primary-500 text-white text-sm"
                    >
                        {{ msg.content }}
                    </div>
                </div>

                <!-- Assistant bubble -->
                <Transition name="fade-in">
                    <div
                        v-if="msg.role === 'assistant' && msg.visible"
                        class="flex justify-start"
                    >
                        <div
                            class="max-w-[85%] rounded-2xl rounded-bl-md px-4 py-2.5 bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] text-sm text-gray-800 dark:text-gray-200"
                        >
                            <!-- eslint-disable-next-line vue/no-v-html -->
                            <div class="chat-content prose prose-sm max-w-none" v-html="renderContent(msg.content)" />
                        </div>
                    </div>
                </Transition>
            </template>

            <!-- Thinking indicator -->
            <div v-if="isThinking" class="flex justify-start">
                <div
                    class="rounded-2xl rounded-bl-md px-5 py-3 bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a] flex items-center gap-2"
                >
                    <ProgressSpinner
                        style="width: 18px; height: 18px"
                        strokeWidth="6"
                        animationDuration="0.8s"
                    />
                    <span class="text-xs text-gray-500">Pensando...</span>
                </div>
            </div>
        </div>

        <Divider class="!m-0" />

        <!-- Input area -->
        <div class="p-3">
            <div class="flex gap-2">
                <InputText
                    v-model="inputText"
                    placeholder="Escribe tu mensaje..."
                    class="flex-1"
                    :disabled="isThinking"
                    :pt="{
                        root: {
                            class:
                                '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !text-sm',
                        },
                    }"
                    @keydown="onKeydown"
                />
                <Button
                    icon="pi pi-send"
                    :loading="isThinking"
                    :disabled="!inputText.trim() || isThinking"
                    class="!rounded-full !w-10 !h-10 !p-0 flex-shrink-0"
                    @click="handleSend"
                />
            </div>
            <p class="text-[10px] text-gray-400 m-0 mt-1.5 text-center">
                El asistente puede cometer errores. Verifica la información importante.
            </p>
        </div>

        <!-- Permission denied state -->
        <div v-if="!canAccess()" class="absolute inset-0 bg-white/80 dark:bg-[#232323]/90 backdrop-blur-sm flex items-center justify-center z-10 rounded-l-3xl">
            <div class="text-center px-6">
                <i class="pi pi-lock !text-3xl !text-gray-400 mb-3" />
                <p class="text-sm font-semibold text-gray-600 mb-1">Sin acceso</p>
                <p class="text-xs text-gray-400">
                    Contacta al administrador para habilitar el asistente de IA.
                </p>
            </div>
        </div>
    </Drawer>
</template>

<style scoped>
.fade-in-enter-active {
    transition: opacity 0.35s ease, transform 0.35s ease;
}
.fade-in-enter-from {
    opacity: 0;
    transform: translateY(8px);
}

.chat-content :deep(a) {
    color: #f68c0f;
    text-decoration: underline;
}
.chat-content :deep(strong) {
    font-weight: 600;
}
</style>
