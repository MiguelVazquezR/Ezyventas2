import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Builds WhatsApp links for the platform support channel.
 * The base URL comes from config/support.php and is shared by HandleInertiaRequests.
 */
export function useSupportWhatsApp() {
    const page = usePage();

    const supportWhatsAppUrl = computed(() => page.props.support?.whatsapp_url ?? null);

    const whatsAppUrl = (message) => {
        if (!supportWhatsAppUrl.value) return null;

        const separator = supportWhatsAppUrl.value.includes('?') ? '&' : '?';

        return `${supportWhatsAppUrl.value}${separator}text=${encodeURIComponent(message)}`;
    };

    return { supportWhatsAppUrl, whatsAppUrl };
}
