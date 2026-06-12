<script setup>
import { ref } from 'vue';
import { useForm, Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useDebounceFn } from '@vueuse/core';
import FormNavigationSidebar from '@/Components/FormNavigationSidebar.vue';
import { useScrollspy } from '@/Composables/useScrollspy';

import VisibilitySection from './Partials/VisibilitySection.vue';
import BasicInfoSection from './Partials/BasicInfoSection.vue';
import BrandingSection from './Partials/BrandingSection.vue';
import DeliverySection from './Partials/DeliverySection.vue';
import PaymentsSection from './Partials/PaymentsSection.vue';
import NotificationsSection from './Partials/NotificationsSection.vue';
import PoliciesSection from './Partials/PoliciesSection.vue';
import FooterSection from './Partials/FooterSection.vue';

const props = defineProps({
    storeConfig: Object,
    storeUrl: String,
    mpConnected: Boolean,
    mpUserId: String,
    mpTestMode: Boolean,
    mpAccountInfo: Object,
});

// --- Helpers ---
function minutesToDHM(totalMinutes) {
    const t = Math.max(0, parseInt(totalMinutes) || 0);
    const days = Math.floor(t / 1440);
    const hours = Math.floor((t % 1440) / 60);
    const minutes = t % 60;
    return { days, hours, minutes };
}

function ensureHash(color) {
    if (!color || color.startsWith('#')) return color;
    return '#' + color;
}

const prepInitial = minutesToDHM(props.storeConfig.preparation_time_minutes ?? 30);
const restockInitial = minutesToDHM(props.storeConfig.out_of_stock_extra_minutes ?? 0);

const form = useForm({
    slug: props.storeConfig.slug || '',
    is_active: props.storeConfig.is_active || false,
    store_name: props.storeConfig.store_name || '',
    description: props.storeConfig.description || '',
    tagline: props.storeConfig.tagline || '',
    logo: null,
    remove_logo: false,
    primary_color: ensureHash(props.storeConfig.primary_color) || '#3B82F6',
    secondary_color: ensureHash(props.storeConfig.secondary_color) || '#1D4ED8',
    welcome_message: props.storeConfig.welcome_message || '',
    accepts_pickup: props.storeConfig.accepts_pickup ?? true,
    accepts_delivery: props.storeConfig.accepts_delivery ?? true,
    allow_out_of_stock_purchases: props.storeConfig.allow_out_of_stock_purchases ?? false,
    out_of_stock_extra_minutes: props.storeConfig.out_of_stock_extra_minutes || 0,
    restock_days: restockInitial.days,
    restock_hours: restockInitial.hours,
    restock_minutes: restockInitial.minutes,
    whatsapp_number: props.storeConfig.whatsapp_number || '',
    delivery_fee: props.storeConfig.delivery_fee || 0,
    free_shipping_minimum: props.storeConfig.free_shipping_minimum || 0,
    preparation_time_minutes: props.storeConfig.preparation_time_minutes || 30,
    prep_days: prepInitial.days,
    prep_hours: prepInitial.hours,
    prep_minutes: prepInitial.minutes,
    banners: [],
    remove_banners: false,
    theme_mode: props.storeConfig.theme_mode || 'light',
    delivery_policy: props.storeConfig.delivery_policy || '',
    terms_policy: props.storeConfig.terms_policy || '',
    footer_note: props.storeConfig.footer_note || '',
    payment_mp_enabled: props.storeConfig.payment_mp_enabled ?? false,
    payment_cash_enabled: props.storeConfig.payment_cash_enabled ?? true,
    cash_instructions: props.storeConfig.cash_instructions || '',
    notify_email_enabled: props.storeConfig.notify_email_enabled ?? false,
    notification_emails: props.storeConfig.notification_emails || [],
});

// Branding — banner removal IDs tracked by child component
const brandingRef = ref(null);

// --- Navigation sections ---
const formSections = [
    { id: 'visibility', label: 'Visibilidad' },
    { id: 'basic', label: 'Información básica' },
    { id: 'branding', label: 'Personalización' },
    { id: 'delivery', label: 'Opciones de entrega' },
    { id: 'payments', label: 'Métodos de pago' },
    { id: 'notifications', label: 'Notificaciones' },
    { id: 'policies', label: 'Políticas de la tienda' },
    { id: 'footer', label: 'Pie de página' },
];
const { activeSection, scrollTo } = useScrollspy(formSections.map(s => s.id));

// --- Submit ---
function submit() {
    form.primary_color = ensureHash(form.primary_color);
    form.secondary_color = ensureHash(form.secondary_color);

    const removedBannerIds = brandingRef.value?.removedBannerIds || [];
    const hasFiles = form.logo instanceof File || form.banners.some(b => b instanceof File);

    form.transform(data => ({
        ...data,
        removed_banner_ids: removedBannerIds,
        ...(hasFiles ? { _method: 'put' } : {}),
    }));

    if (hasFiles) {
        form.post(route('online-store.config.update'));
    } else {
        form.put(route('online-store.config.update'));
    }
}

// --- Policy template ---
const policyTemplate = `<h3>Políticas de la tienda</h3>
<p><strong>Última actualización:</strong> ${new Date().toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' })}</p>

<h3>1. Pedidos y disponibilidad</h3>
<p>Todos los pedidos están sujetos a disponibilidad de inventario. Si un producto no está disponible después de realizar tu pedido, te notificaremos de inmediato y te ofreceremos un reembolso completo o un producto alternativo.</p>

<h3>2. Precios y pagos</h3>
<p>Todos los precios están expresados en pesos mexicanos (MXN) e incluyen impuestos aplicables. Aceptamos los métodos de pago disponibles en nuestra plataforma. El pago debe realizarse en su totalidad al momento de la compra.</p>

<h3>3. Entregas</h3>
<p>Realizamos entregas en los horarios y zonas especificadas al momento de la compra. El tiempo de entrega estimado se mostrará antes de confirmar tu pedido. No nos hacemos responsables por retrasos causados por circunstancias fuera de nuestro control.</p>

<h3>4. Devoluciones y reembolsos</h3>
<p>Aceptamos devoluciones dentro de los 7 días naturales posteriores a la entrega, siempre que el producto esté en su empaque original y sin usar. Los productos perecederos o personalizados no son elegibles para devolución. El reembolso se procesará en un plazo de 5 a 10 días hábiles.</p>

<h3>5. Privacidad</h3>
<p>Tu información personal se utiliza únicamente para procesar tu pedido y mejorar tu experiencia de compra. No compartimos tus datos con terceros sin tu consentimiento.</p>

<h3>6. Contacto</h3>
<p>Para cualquier duda o aclaración, contáctanos a través de WhatsApp o por los medios indicados en nuestra tienda.</p>`;

function loadPolicyTemplate() {
    form.terms_policy = policyTemplate;
}

function hasRealContent(html) {
    if (!html) return false;
    const stripped = html.replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim();
    return stripped.length > 0;
}

const inputPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors' }
};
</script>

<template>
    <Head title="Configuración de tienda" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-7xl mx-auto space-y-6">
            <div class="mb-4">
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200 m-0">Configuración de tienda</h1>
                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1 flex items-center gap-2">
                    <span class="w-1.5 h-1.5 rounded-full" :class="storeConfig.is_active ? 'bg-teal-500 shadow-[0_0_8px_rgba(20,184,166,0.8)] animate-pulse' : 'bg-gray-400'" />
                    {{ storeConfig.is_active ? 'Tu tienda está en línea' : 'Tu tienda está inactiva' }}
                </p>
            </div>

            <div class="flex flex-col md:flex-row gap-6 items-start">
                <!-- Sidebar Navigation -->
                <FormNavigationSidebar :sections="formSections" :activeSection="activeSection" @scrollTo="scrollTo" />

                <!-- Form Container -->
                <div class="w-full md:w-3/4">
                    <form @submit.prevent="submit" class="space-y-6">
                        <VisibilitySection :form="form" :store-url="storeUrl" />
                        <BasicInfoSection :form="form" :input-pt="inputPt" :existing-slug="storeConfig.slug" />
                        <BrandingSection ref="brandingRef" :form="form" :input-pt="inputPt" :initial-banners="storeConfig.banners || []" :store-logo-url="storeConfig.logo_url" />
                        <DeliverySection :form="form" :input-pt="inputPt" />
                        <PaymentsSection :form="form" :input-pt="inputPt"
                            :mp-connected="mpConnected" :mp-test-mode="mpTestMode"
                            :mp-user-id="mpUserId" :mp-account-info="mpAccountInfo" />
                        <NotificationsSection :form="form" :input-pt="inputPt" />
                        <PoliciesSection :form="form" :input-pt="inputPt"
                            :has-real-content="hasRealContent" :load-policy-template="loadPolicyTemplate" />
                        <FooterSection :form="form" :input-pt="inputPt" />

                        <div class="flex justify-end">
                            <Button type="submit" :loading="form.processing" label="Guardar cambios" icon="pi pi-check" class="!rounded-full" />
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>