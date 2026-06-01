<script setup>
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import FormNavigationSidebar from '@/Components/FormNavigationSidebar.vue';
import { useScrollspy } from '@/Composables/useScrollspy';

const props = defineProps({
    storeConfig: Object,
    storeUrl: String,
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

// Compute initial prep time split
const prepInitial = minutesToDHM(props.storeConfig.preparation_time_minutes ?? 30);
const restockInitial = minutesToDHM(props.storeConfig.out_of_stock_extra_minutes ?? 0);

const slugManuallyEdited = ref(false);
const slugAvailable = ref(null);
const checkingSlug = ref(false);
const logoPreview = ref(props.storeConfig.logo_url || null);

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
});

// --- Navigation sections ---
const formSections = [
    { id: 'visibility', label: 'Visibilidad' },
    { id: 'basic', label: 'Información básica' },
    { id: 'branding', label: 'Personalización' },
    { id: 'delivery', label: 'Opciones de entrega' },
    { id: 'policies', label: 'Políticas de la tienda' },
    { id: 'footer', label: 'Pie de página' },
];
const { activeSection, scrollTo } = useScrollspy(formSections.map(s => s.id));

// --- Slug logic ---
watch(() => form.store_name, (newName) => {
    if (!slugManuallyEdited.value && newName) {
        form.slug = newName
            .toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9\s-]/g, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '');
    }
    checkSlug();
});

watch(() => form.slug, () => {
    if (form.slug) {
        checkSlug();
    } else {
        slugAvailable.value = null;
    }
});

const checkSlug = useDebounceFn(async () => {
    if (!form.slug || form.slug.length < 2) {
        slugAvailable.value = null;
        return;
    }
    if (form.slug === props.storeConfig.slug) {
        slugAvailable.value = true;
        return;
    }
    checkingSlug.value = true;
    try {
        const response = await axios.post(route('online-store.config.check-slug'), { slug: form.slug });
        slugAvailable.value = response.data.available;
    } catch {
        slugAvailable.value = null;
    } finally {
        checkingSlug.value = false;
    }
}, 500);

// --- Submit ---
function submit() {
    form.primary_color = ensureHash(form.primary_color);
    form.secondary_color = ensureHash(form.secondary_color);

    const hasFiles = form.logo instanceof File || form.banners.some(b => b instanceof File);

    form.transform(data => ({
        ...data,
        removed_banner_ids: removedBannerIds.value,
        ...(hasFiles ? { _method: 'put' } : {}),
    }));

    if (hasFiles) {
        form.post(route('online-store.config.update'));
    } else {
        form.put(route('online-store.config.update'));
    }
}

// --- Logo ---
function removeLogo() {
    if (logoPreview.value && logoPreview.value.startsWith('blob:')) {
        URL.revokeObjectURL(logoPreview.value);
    }
    logoPreview.value = null;
    form.logo = null;
    form.remove_logo = true;
}

function onLogoSelect(event) {
    const file = event.files[0];
    if (file) {
        if (logoPreview.value && logoPreview.value.startsWith('blob:')) {
            URL.revokeObjectURL(logoPreview.value);
        }
        form.logo = file;
        form.remove_logo = false;
        logoPreview.value = URL.createObjectURL(file);
    }
}

// --- Banners ---
const existingBanners = ref((props.storeConfig.banners || []).map(b => ({ id: b.id, url: b.url })));
const bannerPreviews = ref([...existingBanners.value.map(b => b.url)]);
const removedBannerIds = ref([]);

function onBannerSelect(event) {
    const files = Array.from(event.files || []);
    files.forEach(file => {
        bannerPreviews.value.push(URL.createObjectURL(file));
        form.banners.push(file);
    });
    form.remove_banners = false;
}

function removeBanner(index) {
    // Determine if it's an existing (server-side) banner or a new file
    const existingCount = existingBanners.value.length;
    const isExisting = index < existingCount;

    if (isExisting) {
        // Track the media ID for backend removal
        const banner = existingBanners.value[index];
        if (banner?.id) {
            removedBannerIds.value.push(banner.id);
        }
        existingBanners.value.splice(index, 1);
    } else {
        // It's a new file — revoke its blob URL and remove from form.banners
        const newFileIndex = index - existingCount;
        if (bannerPreviews.value[index]?.startsWith('blob:')) {
            URL.revokeObjectURL(bannerPreviews.value[index]);
        }
        if (newFileIndex < form.banners.length) {
            form.banners.splice(newFileIndex, 1);
        }
    }

    bannerPreviews.value.splice(index, 1);
}

const inputPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors' }
};

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

// Check if the editor has meaningful content (not just empty HTML like <p><br></p>)
function hasRealContent(html) {
    if (!html) return false;
    const stripped = html.replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim();
    return stripped.length > 0;
}
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
                        <!-- Visibility -->
                        <div id="visibility" class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] space-y-4">
                            <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 m-0 pb-2 border-b border-gray-100 dark:border-[#3a3a3a]">Visibilidad</h2>
                            <div class="flex flex-col sm:flex-row sm:items-center gap-4 p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl">
                                <div class="flex-1">
                                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Estado de la tienda</label>
                                    <p class="text-xs text-gray-400 m-0 mt-1">Activa para que tu tienda sea visible al público.</p>
                                </div>
                                <ToggleSwitch v-model="form.is_active" />
                            </div>
                            <div v-if="storeUrl" class="p-4 bg-green-50 dark:bg-green-900/10 rounded-2xl border border-green-100 dark:border-green-900/30 flex items-center gap-3">
                                <i class="pi pi-link text-green-600" />
                                <span class="text-sm text-green-800 dark:text-green-300 break-all flex-1">{{ storeUrl }}</span>
                                <Button as="a" :href="storeUrl" severity="success" target="_blank" label="Ver mi tienda" icon="pi pi-external-link" size="small" class="!rounded-full !shrink-0" />
                            </div>
                        </div>

                        <!-- Basic Info -->
                        <div id="basic" class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] space-y-4">
                            <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 m-0 pb-2 border-b border-gray-100 dark:border-[#3a3a3a]">Información básica</h2>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="flex flex-col gap-1.5">
                                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Nombre de tienda *</label>
                                    <InputText v-model="form.store_name" :pt="inputPt" class="w-full" />
                                    <Message v-if="form.errors.store_name" severity="error" variant="simple" size="small">{{ form.errors.store_name }}</Message>
                                </div>
                                <div class="flex flex-col gap-1.5">
                                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Eslogan</label>
                                    <InputText v-model="form.tagline" :pt="inputPt" class="w-full" placeholder="Calidad y confianza" maxlength="120" />
                                    <p class="text-[11px] text-gray-400 dark:text-gray-500 m-0 leading-relaxed">
                                        <i class="pi pi-info-circle !text-xs mr-1" />
                                        Frase corta que aparece debajo del nombre de tu tienda. Máximo 120 caracteres.
                                    </p>
                                </div>
                                <div class="flex flex-col gap-1.5">
                                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Slug de URL *</label>
                                    <InputText v-model="form.slug" :pt="inputPt" class="w-full" placeholder="mi-tienda"
                                        @focus="slugManuallyEdited = true"
                                        :class="{
                                            '!border-green-500 dark:!border-green-600': slugAvailable === true,
                                            '!border-red-400 dark:!border-red-600': slugAvailable === false,
                                        }" />
                                    <p class="text-[11px] text-gray-400 dark:text-gray-500 m-0 leading-relaxed">
                                        <i class="pi pi-info-circle !text-xs mr-1" />
                                        Identificador único de tu tienda en la URL. Solo letras minúsculas, números y guiones. Se genera automáticamente del nombre.
                                    </p>
                                    <div v-if="checkingSlug" class="flex items-center gap-1.5 text-[11px] text-gray-400 m-0">
                                        <i class="pi pi-spin pi-spinner !text-xs" /> Verificando disponibilidad...
                                    </div>
                                    <div v-else-if="slugAvailable === true && form.slug !== props.storeConfig.slug" class="flex items-center gap-1.5 text-[11px] text-green-600 dark:text-green-500 m-0">
                                        <i class="pi pi-check-circle !text-xs" /> Slug disponible
                                    </div>
                                    <div v-else-if="slugAvailable === false" class="flex items-center gap-1.5 text-[11px] text-red-500 m-0">
                                        <i class="pi pi-times-circle !text-xs" /> Este slug ya está en uso. Cambia el nombre de tu tienda o edítalo manualmente.
                                    </div>
                                    <Message v-if="form.errors.slug" severity="error" variant="simple" size="small">{{ form.errors.slug }}</Message>
                                </div>
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Descripción</label>
                                <Textarea v-model="form.description" :pt="inputPt" rows="3" class="w-full" />
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Mensaje de bienvenida</label>
                                <InputText v-model="form.welcome_message" :pt="inputPt" class="w-full" placeholder="¡Bienvenido a nuestra tienda!" />
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">WhatsApp de contacto</label>
                                <InputText v-model="form.whatsapp_number" :pt="inputPt" class="w-full" placeholder="+521234567890" maxlength="20" />
                                <p class="text-[11px] text-gray-400 dark:text-gray-500 m-0 leading-relaxed">
                                    <i class="pi pi-info-circle !text-xs mr-1" />
                                    Número de WhatsApp para que tus clientes te contacten. Se mostrará un botón flotante en tu tienda.
                                </p>
                                <Message v-if="form.errors.whatsapp_number" severity="error" variant="simple" size="small">{{ form.errors.whatsapp_number }}</Message>
                            </div>
                        </div>

                        <!-- Branding -->
                        <div id="branding" class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] space-y-4">
                            <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 m-0 pb-2 border-b border-gray-100 dark:border-[#3a3a3a]">Personalización</h2>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="flex flex-col gap-1.5">
                                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Color primario</label>
                                    <div class="flex items-center gap-3">
                                        <ColorPicker v-model="form.primary_color" :pt="{ input: { class: '!w-10 !h-10 !rounded-xl' } }" />
                                        <InputText v-model="form.primary_color" :pt="inputPt" class="w-32 !font-mono" />
                                    </div>
                                </div>
                                <div class="flex flex-col gap-1.5">
                                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Color secundario</label>
                                    <div class="flex items-center gap-3">
                                        <ColorPicker v-model="form.secondary_color" :pt="{ input: { class: '!w-10 !h-10 !rounded-xl' } }" />
                                        <InputText v-model="form.secondary_color" :pt="inputPt" class="w-32 !font-mono" />
                                    </div>
                                </div>
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Logo</label>
                                <div class="flex items-center gap-4">
                                    <div v-if="logoPreview" class="relative group">
                                        <img :src="logoPreview" class="h-16 w-16 object-contain rounded-xl bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a]" />
                                        <button type="button" @click="removeLogo" class="absolute -top-2 -right-2 w-5 h-5 bg-red-500 text-white rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity shadow-md hover:bg-red-600" title="Eliminar logo">
                                            <i class="pi pi-times !text-[10px]" />
                                        </button>
                                    </div>
                                    <FileUpload mode="basic" accept="image/*" :maxFileSize="2000000" customUpload auto @select="onLogoSelect" chooseLabel="Seleccionar logo" :pt="{ chooseButton: { class: '!rounded-xl' } }" />
                                </div>
                                <Message v-if="form.errors.logo" severity="error" variant="simple" size="small">{{ form.errors.logo }}</Message>
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Tema de la tienda</label>
                                <SelectButton v-model="form.theme_mode" :options="[
                                    { label: 'Claro', value: 'light', icon: 'pi pi-sun' },
                                    { label: 'Oscuro', value: 'dark', icon: 'pi pi-moon' },
                                ]" optionLabel="label" optionValue="value"
                                    :pt="{
                                        root: { class: '!bg-transparent !border-0 !p-0' },
                                        button: { class: '!text-xs !rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-gray-600 dark:!text-gray-400' }
                                    }" />
                                <p class="text-[11px] text-gray-400 dark:text-gray-500 m-0 leading-relaxed">
                                    <i class="pi pi-info-circle !text-xs mr-1" />
                                    Define si los fondos de tu tienda serán claros u oscuros.
                                </p>
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Banners</label>
                                <p class="text-[11px] text-gray-400 dark:text-gray-500 m-0 leading-relaxed">
                                    <i class="pi pi-info-circle !text-xs mr-1" />
                                    Imágenes que aparecerán en un carrusel al inicio de tu tienda. Úsalas para promociones, nuevos productos u ofertas. Máximo 3 banners.
                                </p>
                                <div v-if="bannerPreviews.length > 0" class="flex gap-3 flex-wrap mt-1">
                                    <div v-for="(url, i) in bannerPreviews" :key="i" class="relative group">
                                        <img :src="url" class="h-24 w-40 object-cover rounded-xl border border-gray-100 dark:border-[#3a3a3a]" />
                                        <button type="button" @click="removeBanner(i)"
                                            class="absolute -top-1.5 -right-1.5 w-5 h-5 bg-red-500 hover:bg-red-600 text-white rounded-full flex items-center justify-center shadow-md transition-colors"
                                            title="Eliminar banner">
                                            <i class="pi pi-times !text-[9px]" />
                                        </button>
                                    </div>
                                </div>
                                <FileUpload mode="basic" accept="image/*" :maxFileSize="4000000" customUpload auto multiple @select="onBannerSelect"
                                    chooseLabel="Agregar banner" :disabled="bannerPreviews.length >= 3"
                                    :pt="{ chooseButton: { class: '!rounded-xl' } }" />
                                <Message v-if="form.errors.banners" severity="error" variant="simple" size="small">{{ form.errors.banners }}</Message>
                            </div>
                        </div>

                        <!-- Delivery -->
                        <div id="delivery" class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] space-y-4">
                            <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 m-0 pb-2 border-b border-gray-100 dark:border-[#3a3a3a]">Opciones de entrega</h2>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl h-full">
                                    <div class="pr-3">
                                        <span class="text-sm font-medium dark:text-white">Aceptar recoger en tienda</span>
                                        <p class="text-xs text-gray-400 m-0">Los clientes pueden recoger pedidos en tu ubicación.</p>
                                    </div>
                                    <ToggleSwitch v-model="form.accepts_pickup" class="shrink-0" />
                                </div>
                                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl h-full">
                                    <div class="pr-3">
                                        <span class="text-sm font-medium dark:text-white">Aceptar envíos a domicilio</span>
                                        <p class="text-xs text-gray-400 m-0">Enviar pedidos a la dirección del cliente.</p>
                                    </div>
                                    <ToggleSwitch v-model="form.accepts_delivery" class="shrink-0" />
                                </div>
                            </div>
                            <template v-if="form.accepts_delivery">
                                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl">
                                    <div>
                                        <span class="text-sm font-medium dark:text-white">Permitir comprar productos agotados</span>
                                        <p class="text-xs text-gray-400 m-0">Los clientes podrán pedir productos sin stock, con tiempo extra de preparación.</p>
                                    </div>
                                    <ToggleSwitch v-model="form.allow_out_of_stock_purchases" />
                                </div>
                                <div v-if="form.allow_out_of_stock_purchases" class="flex flex-col gap-1.5">
                                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Tiempo extra por resurtimiento</label>
                                    <div class="grid grid-cols-3 gap-3">
                                        <div class="flex flex-col gap-1">
                                            <span class="text-[9px] text-gray-400">Días</span>
                                            <InputNumber v-model="form.restock_days" :min="0" :max="30" :pt="{ input: { root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-sm !text-center' } } }" />
                                        </div>
                                        <div class="flex flex-col gap-1">
                                            <span class="text-[9px] text-gray-400">Horas</span>
                                            <InputNumber v-model="form.restock_hours" :min="0" :max="23" :pt="{ input: { root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-sm !text-center' } } }" />
                                        </div>
                                        <div class="flex flex-col gap-1">
                                            <span class="text-[9px] text-gray-400">Minutos</span>
                                            <InputNumber v-model="form.restock_minutes" :min="0" :max="59" :pt="{ input: { root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-sm !text-center' } } }" />
                                        </div>
                                    </div>
                                    <p class="text-[11px] text-gray-400 dark:text-gray-500 m-0 leading-relaxed">
                                        <i class="pi pi-info-circle !text-xs mr-1" />
                                        Tiempo adicional de preparación cuando un producto requiere resurtimiento.
                                    </p>
                                    <Message v-if="form.errors.out_of_stock_extra_minutes" severity="error" variant="simple" size="small">{{ form.errors.out_of_stock_extra_minutes }}</Message>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="flex flex-col gap-1.5">
                                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Costo de envío</label>
                                        <InputNumber v-model="form.delivery_fee" mode="currency" currency="MXN" :pt="{ input: { root: { class: 'w-full !rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-sm' } } }" />
                                        <Message v-if="form.errors.delivery_fee" severity="error" variant="simple" size="small">{{ form.errors.delivery_fee }}</Message>
                                    </div>
                                    <div class="flex flex-col gap-1.5">
                                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Envío gratis a partir de</label>
                                        <InputNumber v-model="form.free_shipping_minimum" mode="currency" currency="MXN" :pt="{ input: { root: { class: 'w-full !rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-sm' } } }" />
                                        <p class="text-[11px] text-gray-400 dark:text-gray-500 m-0 leading-relaxed">
                                            <i class="pi pi-info-circle !text-xs mr-1" />
                                            Si el total del pedido alcanza este monto, el envío será gratis. Deja en 0 para cobrar envío siempre.
                                        </p>
                                        <Message v-if="form.errors.free_shipping_minimum" severity="error" variant="simple" size="small">{{ form.errors.free_shipping_minimum }}</Message>
                                    </div>
                                </div>
                                <div class="flex flex-col gap-1.5">
                                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Tiempo de preparación</label>
                                    <div class="grid grid-cols-3 gap-3">
                                        <div class="flex flex-col gap-1">
                                            <span class="text-[9px] text-gray-400">Días</span>
                                            <InputNumber v-model="form.prep_days" :min="0" :max="30" :pt="{ input: { root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-sm !text-center' } } }" />
                                        </div>
                                        <div class="flex flex-col gap-1">
                                            <span class="text-[9px] text-gray-400">Horas</span>
                                            <InputNumber v-model="form.prep_hours" :min="0" :max="23" :pt="{ input: { root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-sm !text-center' } } }" />
                                        </div>
                                        <div class="flex flex-col gap-1">
                                            <span class="text-[9px] text-gray-400">Minutos</span>
                                            <InputNumber v-model="form.prep_minutes" :min="0" :max="59" :pt="{ input: { root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-sm !text-center' } } }" />
                                        </div>
                                    </div>
                                    <p class="text-[11px] text-gray-400 dark:text-gray-500 m-0 leading-relaxed">
                                        <i class="pi pi-info-circle !text-xs mr-1" />
                                        Tiempo estimado que tardas en preparar un pedido.
                                    </p>
                                </div>
                                <div class="flex flex-col gap-1.5">
                                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Política de envío</label>
                                    <Textarea v-model="form.delivery_policy" :pt="inputPt" rows="3" class="w-full" />
                                </div>
                            </template>
                        </div>

                        <!-- Policies -->
                        <div id="policies" class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] space-y-4">
                            <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 m-0 pb-2 border-b border-gray-100 dark:border-[#3a3a3a]">Políticas de la tienda</h2>
                            <div class="flex flex-col gap-1.5">
                                <div class="flex items-center justify-between">
                                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Políticas, devoluciones y términos</label>
                                    <Button type="button" @click="loadPolicyTemplate" severity="secondary" outlined size="small" label="Cargar plantilla" icon="pi pi-file-edit" class="!rounded-xl !text-xs" :disabled="hasRealContent(form.terms_policy)" />
                                </div>
                                <p v-if="!hasRealContent(form.terms_policy)" class="text-[11px] text-gray-400 dark:text-gray-500 m-0 leading-relaxed">
                                    <i class="pi pi-lightbulb !text-xs mr-1" />
                                    Usa el botón "Cargar plantilla" para comenzar con una plantilla prediseñada que puedes personalizar.
                                </p>
                                <Editor v-model="form.terms_policy" editorStyle="height: 250px" class="w-full"
                                    :pt="{
                                        root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a]' }
                                    }">
                                    <template v-slot:toolbar>
                                        <span class="ql-formats">
                                            <select class="ql-header" defaultValue="3"><option value="1">Título</option><option value="2">Subtítulo</option><option value="3">Normal</option></select>
                                        </span>
                                        <span class="ql-formats">
                                            <button class="ql-bold"></button>
                                            <button class="ql-italic"></button>
                                            <button class="ql-underline"></button>
                                        </span>
                                        <span class="ql-formats">
                                            <button class="ql-list" value="ordered"></button>
                                            <button class="ql-list" value="bullet"></button>
                                        </span>
                                        <span class="ql-formats">
                                            <button class="ql-link"></button>
                                        </span>
                                    </template>
                                </Editor>
                                <p class="text-[11px] text-gray-400 dark:text-gray-500 m-0 leading-relaxed">
                                    <i class="pi pi-info-circle !text-xs mr-1" />
                                    Estas políticas se mostrarán en una página accesible desde el pie de tu tienda.
                                </p>
                                <Message v-if="form.errors.terms_policy" severity="error" variant="simple" size="small">{{ form.errors.terms_policy }}</Message>
                            </div>
                        </div>

                        <!-- Footer -->
                        <div id="footer" class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] space-y-4">
                            <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 m-0 pb-2 border-b border-gray-100 dark:border-[#3a3a3a]">Pie de página</h2>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Nota de pie de página</label>
                                <InputText v-model="form.footer_note" :pt="inputPt" class="w-full" placeholder="© 2026 Mi tienda. Todos los derechos reservados." />
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <Button type="submit" :loading="form.processing" label="Guardar cambios" icon="pi pi-check" class="!rounded-full" />
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
