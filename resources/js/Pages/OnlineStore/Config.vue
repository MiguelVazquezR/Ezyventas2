<script setup>
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';

const props = defineProps({
    storeConfig: Object,
    storeUrl: String,
});

const slugManuallyEdited = ref(false);
const slugAvailable = ref(null); // null = checking, true = available, false = taken
const checkingSlug = ref(false);
const logoPreview = ref(props.storeConfig.logo_url || null);

const form = useForm({
    slug: props.storeConfig.slug || '',
    is_active: props.storeConfig.is_active || false,
    store_name: props.storeConfig.store_name || '',
    description: props.storeConfig.description || '',
    logo: null,
    remove_logo: false,
    primary_color: props.storeConfig.primary_color || '#3B82F6',
    secondary_color: props.storeConfig.secondary_color || '#1D4ED8',
    welcome_message: props.storeConfig.welcome_message || '',
    accepts_pickup: props.storeConfig.accepts_pickup ?? true,
    accepts_delivery: props.storeConfig.accepts_delivery ?? true,
    delivery_fee: props.storeConfig.delivery_fee || 0,
    free_shipping_minimum: props.storeConfig.free_shipping_minimum || 0,
    preparation_time_minutes: props.storeConfig.preparation_time_minutes || 30,
    delivery_policy: props.storeConfig.delivery_policy || '',
    footer_note: props.storeConfig.footer_note || '',
});

// Auto-generate slug from store name (only if user hasn't manually edited the slug)
watch(() => form.store_name, (newName) => {
    if (!slugManuallyEdited.value && newName) {
        form.slug = newName
            .toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '') // remove accents
            .replace(/[^a-z0-9\s-]/g, '')  // remove special chars
            .replace(/\s+/g, '-')           // spaces → hyphens
            .replace(/-+/g, '-')            // collapse multiple hyphens
            .replace(/^-|-$/g, '');         // trim hyphens
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

    // If slug hasn't changed from the original, it's available
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

function submit() {
    if (form.logo instanceof File) {
        // Inertia + file uploads: must use POST with _method=PUT via transform()
        // form.put() with forceFormData loses non-file fields in the FormData
        form.transform(data => ({
            ...data,
            _method: 'put',
        })).post(route('online-store.config.update'));
    } else {
        form.put(route('online-store.config.update'));
    }
}

function removeLogo() {
    // Revoke preview URL if it's a blob
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
        // Revoke previous preview URL to avoid memory leaks
        if (logoPreview.value && logoPreview.value.startsWith('blob:')) {
            URL.revokeObjectURL(logoPreview.value);
        }
        form.logo = file;
        form.remove_logo = false;
        logoPreview.value = URL.createObjectURL(file);
    }
}

const inputPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors' }
};
</script>

<template>
    <Head title="Configuración de tienda" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-4xl mx-auto space-y-6">
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                <div class="mb-8">
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Configuración de tienda</h1>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full" :class="storeConfig.is_active ? 'bg-teal-500 shadow-[0_0_8px_rgba(20,184,166,0.8)] animate-pulse' : 'bg-gray-400'" />
                        {{ storeConfig.is_active ? 'Tu tienda está en línea' : 'Tu tienda está inactiva' }}
                    </p>
                </div>

                <form @submit.prevent="submit" class="space-y-8">
                    <!-- Activation & Store URL -->
                    <section class="space-y-4">
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
                    </section>

                    <!-- Basic Info -->
                    <section class="space-y-4">
                        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 m-0 pb-2 border-b border-gray-100 dark:border-[#3a3a3a]">Información básica</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Nombre de tienda *</label>
                                <InputText v-model="form.store_name" :pt="inputPt" class="w-full" />
                                <Message v-if="form.errors.store_name" severity="error" variant="simple" size="small">{{ form.errors.store_name }}</Message>
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
                    </section>

                    <!-- Branding -->
                    <section class="space-y-4">
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
                    </section>

                    <!-- Delivery & Footer -->
                    <section class="space-y-4">
                        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 m-0 pb-2 border-b border-gray-100 dark:border-[#3a3a3a]">Opciones de entrega</h2>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                            <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl">
                                <div>
                                    <span class="text-sm font-medium dark:text-white">Aceptar recoger en tienda</span>
                                    <p class="text-xs text-gray-400 m-0">Los clientes pueden recoger pedidos en tu ubicación.</p>
                                </div>
                                <ToggleSwitch v-model="form.accepts_pickup" />
                            </div>
                            <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl">
                                <div>
                                    <span class="text-sm font-medium dark:text-white">Aceptar envíos a domicilio</span>
                                    <p class="text-xs text-gray-400 m-0">Enviar pedidos a la dirección del cliente.</p>
                                </div>
                                <ToggleSwitch v-model="form.accepts_delivery" />
                            </div>
                        </div>
                        <template v-if="form.accepts_delivery">
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
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Tiempo de preparación (min)</label>
                                <InputNumber v-model="form.preparation_time_minutes" :pt="{ input: { root: { class: 'w-full !rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-sm' } } }" />
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Política de envío</label>
                                <Textarea v-model="form.delivery_policy" :pt="inputPt" rows="3" class="w-full" />
                            </div>
                        </template>
                    </section>

                    <!-- Footer -->
                    <section class="space-y-4">
                        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 m-0 pb-2 border-b border-gray-100 dark:border-[#3a3a3a]">Pie de página</h2>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Nota de pie de página</label>
                            <InputText v-model="form.footer_note" :pt="inputPt" class="w-full" placeholder="© 2026 Mi tienda. Todos los derechos reservados." />
                        </div>
                    </section>

                    <div class="flex justify-end pt-4 border-t border-gray-100 dark:border-[#3a3a3a]">
                        <Button type="submit" :loading="form.processing" label="Guardar cambios" icon="pi pi-check" class="!rounded-full" />
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
