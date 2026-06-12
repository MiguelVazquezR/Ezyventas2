<script setup>
import { ref, watch } from 'vue';
import { useDebounceFn } from '@vueuse/core';

const props = defineProps({
    form: { type: Object, required: true },
    inputPt: { type: Object, required: true },
    existingSlug: { type: String, default: '' },
});

const slugManuallyEdited = ref(false);
const slugAvailable = ref(null);
const checkingSlug = ref(false);

watch(() => props.form.store_name, (newName) => {
    if (!slugManuallyEdited.value && newName) {
        props.form.slug = newName
            .toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9\s-]/g, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '');
    }
    checkSlug();
});

watch(() => props.form.slug, () => {
    if (props.form.slug) {
        checkSlug();
    } else {
        slugAvailable.value = null;
    }
});

const checkSlug = useDebounceFn(async () => {
    if (!props.form.slug || props.form.slug.length < 2) {
        slugAvailable.value = null;
        return;
    }
    if (props.form.slug === props.existingSlug) {
        slugAvailable.value = true;
        return;
    }
    checkingSlug.value = true;
    try {
        const response = await axios.post(route('online-store.config.check-slug'), { slug: props.form.slug });
        slugAvailable.value = response.data.available;
    } catch {
        slugAvailable.value = null;
    } finally {
        checkingSlug.value = false;
    }
}, 500);
</script>

<template>
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
                <div v-else-if="slugAvailable === true && form.slug !== existingSlug" class="flex items-center gap-1.5 text-[11px] text-green-600 dark:text-green-500 m-0">
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
</template>
