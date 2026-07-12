import { ref, nextTick } from 'vue';
import { useToast } from 'primevue/usetoast';

/**
 * Composable for the AI chat drawer.
 * Manages conversation state, message sending, and the fade-in reveal.
 *
 * No Pinia — state is local to the composable, consistent with the rest of the app.
 */
export function useAiChat() {
    const conversationId = ref(null);
    const messages = ref([]);
    const isThinking = ref(false);
    const toast = useToast();

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
     */
    async function sendMessage(text) {
        if (!text.trim()) return;

        await ensureConversation();

        // Optimistic user message
        messages.value.push({ role: 'user', content: text, visible: true });
        isThinking.value = true;

        try {
            const { data } = await window.axios.post(
                `/ai-agent/conversations/${conversationId.value}/messages`,
                { message: text }
            );

            // Push with visible:false so the <Transition> animates it in
            messages.value.push({
                role: 'assistant',
                content: data.message.content,
                tool_calls: data.message.tool_calls,
                visible: false,
            });

            await nextTick();
            messages.value.at(-1).visible = true;
        } catch (e) {
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
