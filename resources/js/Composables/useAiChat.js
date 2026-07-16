import { ref, nextTick, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';

/**
 * Composable for the AI chat drawer.
 * Manages conversation state, message sending, and the fade-in reveal.
 *
 * No Pinia — state is local to the composable, consistent with the rest of the app.
 * State persists across drawer open/close cycles but resets on page navigation.
 */
export function useAiChat() {
    const conversationId = ref(null);
    const messages = ref([]);
    const isThinking = ref(false);
    const toast = useToast();
    const page = usePage();

    // Reset conversation when navigating to a different page
    let lastUrl = page.url;
    watch(
        () => page.url,
        (url) => {
            if (url !== lastUrl) {
                lastUrl = url;
                reset();
            }
        }
    );

    /**
     * Start a new conversation (or reuse the existing one).
     */
    async function ensureConversation() {
        if (conversationId.value) return;

        try {
            // Sanctum SPA: obtain CSRF cookie before first POST
            await window.axios.get('/sanctum/csrf-cookie');

            const { data } = await window.axios.post('/ai-agent/conversations');
            conversationId.value = data.conversation.id;
        } catch (e) {
            toast.add({
                severity: 'error',
                summary: 'Error',
                detail: 'No se pudo iniciar la conversación con el asistente.',
                life: 5000,
            });
            throw e;
        }
    }

    /**
     * Send a user message and append the assistant's reply with a fade-in.
     *
     * The user message appears instantly (optimistic) while the AI thinks.
     */
    async function sendMessage(text) {
        if (!text.trim()) return;

        // Show the user's message immediately — don't wait for API calls
        messages.value.push({ role: 'user', content: text, visible: true });
        isThinking.value = true;

        try {
            await ensureConversation();

            const { data } = await window.axios.post(
                `/ai-agent/conversations/${conversationId.value}/messages`,
                { message: text }
            );

            if (data.message.limit_exceeded || data.message.module_inactive) {
                messages.value.push({
                    role: 'assistant',
                    content: null,
                    limitExceeded: data.message.limit_exceeded,
                    moduleInactive: data.message.module_inactive,
                    visible: true,
                });
            } else {
                // Push with visible:false so the <Transition> animates it in
                messages.value.push({
                    role: 'assistant',
                    content: data.message.content,
                    tool_calls: data.message.tool_calls,
                    visible: false,
                });

                await nextTick();
                messages.value.at(-1).visible = true;
            }
        } catch (e) {
            // Remove the optimistic user message on failure
            if (messages.value.at(-1)?.role === 'user') {
                messages.value.pop();
            }

            const detail = e.response?.data?.message
                ?? 'El asistente no pudo procesar tu mensaje. Intenta de nuevo.';

            toast.add({
                severity: 'error',
                summary: 'Error del asistente',
                detail,
                life: 7000,
            });

            messages.value.push({
                role: 'assistant',
                content: '❌ Ocurrió un error al procesar tu mensaje.',
                visible: true,
            });
        } finally {
            isThinking.value = false;
        }
    }

    /** Clear the conversation and start fresh. */
    function reset() {
        conversationId.value = null;
        messages.value = [];
    }

    return {
        conversationId,
        messages,
        isThinking,
        sendMessage,
        reset,
    };
}
