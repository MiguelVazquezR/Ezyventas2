<script setup>
import { ref } from 'vue';

const props = defineProps({
    form: { type: Object, required: true },
    inputPt: { type: Object, required: true },
    initialBanners: { type: Array, default: () => [] },
    storeLogoUrl: { type: String, default: null },
});

const logoPreview = ref(props.storeLogoUrl || null);

function removeLogo() {
    if (logoPreview.value && logoPreview.value.startsWith('blob:')) {
        URL.revokeObjectURL(logoPreview.value);
    }
    logoPreview.value = null;
    props.form.logo = null;
    props.form.remove_logo = true;
}

function onLogoSelect(event) {
    const file = event.files[0];
    if (file) {
        if (logoPreview.value && logoPreview.value.startsWith('blob:')) {
            URL.revokeObjectURL(logoPreview.value);
        }
        props.form.logo = file;
        props.form.remove_logo = false;
        logoPreview.value = URL.createObjectURL(file);
    }
}

// --- Banners ---
const existingBanners = ref((props.initialBanners || []).map(b => ({ id: b.id, url: b.url })));
const bannerPreviews = ref([...existingBanners.value.map(b => b.url)]);
const removedBannerIds = ref([]);

function onBannerSelect(event) {
    const files = Array.from(event.files || []);
    files.forEach(file => {
        bannerPreviews.value.push(URL.createObjectURL(file));
        props.form.banners.push(file);
    });
    props.form.remove_banners = false;
}

function removeBanner(index) {
    const existingCount = existingBanners.value.length;
    const isExisting = index < existingCount;

    if (isExisting) {
        const banner = existingBanners.value[index];
        if (banner?.id) {
            removedBannerIds.value.push(banner.id);
        }
        existingBanners.value.splice(index, 1);
    } else {
        const newFileIndex = index - existingCount;
        if (bannerPreviews.value[index]?.startsWith('blob:')) {
            URL.revokeObjectURL(bannerPreviews.value[index]);
        }
        if (newFileIndex < props.form.banners.length) {
            props.form.banners.splice(newFileIndex, 1);
        }
    }
    bannerPreviews.value.splice(index, 1);
}

defineExpose({ removedBannerIds });
</script>

<template>
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
</template>
