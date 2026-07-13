<script setup>
import { ref, nextTick, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useAiChat } from '@/composables/useAiChat';

const props = defineProps({
    /** Control the drawer visibility from parent. */
    visible: Boolean,
});

const emit = defineEmits(['update:visible']);

const { messages, isThinking, sendMessage } = useAiChat();

const inputText = ref('');
const messagesContainer = ref(null);
const usagePanel = ref(null);
const usagePct = ref(0);

function toggleUsage(event) {
    usagePanel.value?.toggle(event);
}

/** Fetch usage data when drawer opens. */
watch(() => props.visible, async (isVisible) => {
    if (isVisible) {
        try {
            const { data } = await window.axios.get('/ai-agent/usage');
            usagePct.value = data.percentage;
        } catch {
            // Silently fail — usage display is non-critical
        }
    }
});

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

async function handleSend() {
    const text = inputText.value.trim();
    if (!text || isThinking.value) return;

    inputText.value = '';

    await sendMessage(text);
}

function onKeydown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        handleSend();
    }
}

function goToManageSubscription() {
    router.visit(route('subscription.manage'));
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

/** Reuse the same ProgressBar PT pattern from PlanDetailsCard */
const progressBarPt = {
    root: { class: '!h-1.5 !bg-gray-200 dark:!bg-[#2a2a2a] !rounded-full overflow-hidden' },
    value: { class: '!bg-blue-500' },
};
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
                <div class="flex-1 min-w-0">
                    <h3 class="m-0 text-sm font-semibold text-gray-900 dark:text-white">
                        Asistente IA
                    </h3>
                    <p class="m-0 text-[10px] uppercase tracking-widest font-bold text-gray-500">
                        EzyVentas AI
                    </p>
                </div>
                <Button
                    ref="usageButton"
                    icon="pi pi-chart-bar"
                    text
                    rounded
                    size="small"
                    :pt="{ root: { class: '!text-gray-400 hover:!text-gray-600 dark:hover:!text-gray-300' } }"
                    @click="toggleUsage"
                />
                <OverlayPanel ref="usagePanel" :pt="{ content: { class: '!rounded-2xl' } }">
                    <div class="p-3 w-48">
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-2">
                            Uso este mes
                        </p>
                        <ProgressBar
                            :value="usagePct"
                            :showValue="false"
                            :pt="progressBarPt"
                        />
                        <p class="text-xs text-gray-500 dark:text-gray-400 m-0 mt-1.5 text-right tabular-nums">
                            {{ usagePct }}%
                        </p>
                    </div>
                </OverlayPanel>
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

                <!-- Limit exceeded card -->
                <div
                    v-if="msg.role === 'assistant' && msg.limitExceeded && msg.visible"
                    class="flex justify-start"
                >
                    <div
                        class="max-w-[85%] rounded-2xl px-4 py-3 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 text-sm"
                    >
                        <p class="font-semibold text-amber-800 dark:text-amber-300 m-0 mb-1">
                            Alcanzaste tu límite mensual
                        </p>
                        <p class="text-amber-700 dark:text-amber-400 m-0 mb-3">
                            Has usado tus {{ msg.limit }} consultas de este mes. Puedes ampliar tu límite desde tu suscripción.
                        </p>
                        <Button
                            label="Ampliar límite"
                            size="small"
                            class="!rounded-xl !text-xs !font-bold"
                            @click="goToManageSubscription"
                        />
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
