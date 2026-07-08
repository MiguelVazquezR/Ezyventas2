<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    note: {
        type: Object,
        default: () => null
    },
    gallery: {
        type: Array,
        default: () => [],
    },
});

const isEditing = !!props.note;

// Estado del formulario
const form = useForm({
    title: props.note?.title || '',
    version: props.note?.version || '',
    excerpt: props.note?.excerpt || '',
    content: props.note?.content || '',
    is_published: props.note ? !!props.note.is_published : false,
    is_banner: props.note ? !!props.note.is_banner : false,
    banner_title: props.note?.banner_title || '',
    media: [], // Nuevos archivos de galería
    deleted_media: [], // IDs de archivos existentes a eliminar
    banner_image: null, // Nueva imagen de banner
    deleted_banner: false, // Si se debe eliminar el banner existente
});

// Medios existentes de la galería (cargados por separado del banner)
const existingMedia = ref(props.gallery || []);
const existingBannerUrl = ref(props.note?.banner_image_url || null);
const bannerPreviewUrl = ref(null);

const onFileSelect = (event) => {
    form.media = event.files;
};

const onFileRemove = (event) => {
    form.media = form.media.filter(f => f.name !== event.file.name);
};

const onBannerSelect = (event) => {
    form.banner_image = event.files[0];
    form.deleted_banner = false;
    // Generar preview
    const reader = new FileReader();
    reader.onload = (e) => { bannerPreviewUrl.value = e.target.result; };
    reader.readAsDataURL(event.files[0]);
};

const onBannerRemove = () => {
    form.banner_image = null;
    bannerPreviewUrl.value = null;
    if (existingBannerUrl.value) {
        form.deleted_banner = true;
        existingBannerUrl.value = null;
    }
};

const removeExistingMedia = (mediaId) => {
    form.deleted_media.push(mediaId);
    existingMedia.value = existingMedia.value.filter(m => m.id !== mediaId);
};

const submit = () => {
    if (isEditing) {
        form.transform((data) => ({
            ...data,
            _method: 'PUT',
        })).post(route('admin.release-notes.update', props.note.id), {
            preserveScroll: true,
        });
    } else {
        form.post(route('admin.release-notes.store'), {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <form @submit.prevent="submit" class="flex flex-col gap-6">
        
        <!-- Errores generales -->
        <Message v-if="Object.keys(form.errors).length > 0" severity="error">
            Por favor, corrige los errores en el formulario antes de continuar.
        </Message>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Título -->
            <div class="flex flex-col gap-2">
                <label for="title" class="font-bold text-gray-700 dark:text-gray-200">Título de la novedad <span class="text-red-500">*</span></label>
                <InputText id="title" v-model="form.title" placeholder="Ej. Nuevo Punto de Venta" :class="{'p-invalid': form.errors.title}" />
                <small v-if="form.errors.title" class="text-red-500">{{ form.errors.title }}</small>
            </div>

            <!-- Versión -->
            <div class="flex flex-col gap-2">
                <label for="version" class="font-bold text-gray-700 dark:text-gray-200">Versión (Opcional)</label>
                <InputText id="version" v-model="form.version" placeholder="Ej. v2.1.0" :class="{'p-invalid': form.errors.version}" />
                <small v-if="form.errors.version" class="text-red-500">{{ form.errors.version }}</small>
            </div>

            <!-- Resumen / Excerpt -->
            <div class="flex flex-col gap-2 md:col-span-2">
                <label for="excerpt" class="font-bold text-gray-700 dark:text-gray-200">Resumen corto</label>
                <Textarea id="excerpt" v-model="form.excerpt" rows="2" placeholder="Breve descripción que aparecerá en la lista y notificaciones..." :class="{'p-invalid': form.errors.excerpt}" />
                <small v-if="form.errors.excerpt" class="text-red-500">{{ form.errors.excerpt }}</small>
            </div>

            <!-- Contenido Rico -->
            <div class="flex flex-col gap-2 md:col-span-2">
                <label for="content" class="font-bold text-gray-700 dark:text-gray-200">Contenido Completo <span class="text-red-500">*</span></label>
                <Editor v-model="form.content" editorStyle="height: 300px" placeholder="Describe a detalle las nuevas funciones..." />
                <small v-if="form.errors.content" class="text-red-500">{{ form.errors.content }}</small>
            </div>

            <!-- Multimedia (Spatie Media Library) -->
            <div class="flex flex-col gap-4 md:col-span-2 bg-gray-50 dark:bg-gray-800/50 p-4 rounded-lg border border-gray-200 dark:border-gray-700">
                <label class="font-bold text-gray-700 dark:text-gray-200">Archivos Multimedia (Imágenes o Videos)</label>
                
                <!-- Galería existente (Al Editar) -->
                <div v-if="existingMedia.length > 0">
                    <p class="text-sm text-gray-500 mb-2">Archivos ya subidos:</p>
                    <div class="flex flex-wrap gap-4">
                        <div v-for="media in existingMedia" :key="media.id" class="relative group">
                            <img v-if="media.mime_type.startsWith('image/')" :src="media.original_url" class="w-32 h-32 object-cover rounded-lg shadow-sm border border-gray-300 dark:border-gray-600" />
                            <video v-else-if="media.mime_type.startsWith('video/')" :src="media.original_url" class="w-32 h-32 object-cover rounded-lg shadow-sm border border-gray-300 dark:border-gray-600" />
                            <div v-else class="w-32 h-32 flex items-center justify-center bg-gray-200 dark:bg-gray-700 rounded-lg shadow-sm border border-gray-300 dark:border-gray-600">
                                <i class="pi pi-file text-3xl text-gray-400"></i>
                            </div>
                            
                            <button type="button" @click="removeExistingMedia(media.id)" class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center hover:bg-red-600 shadow-md opacity-0 group-hover:opacity-100 transition-opacity" v-tooltip.top="'Eliminar archivo'">
                                <i class="pi pi-times text-xs"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Subir nuevos -->
                <div class="mt-2">
                    <p class="text-sm text-gray-500 mb-2">Añadir nuevos archivos:</p>
                    <FileUpload name="media[]" mode="advanced" multiple accept="image/*,video/mp4,video/webm" :maxFileSize="20000000" 
                                @select="onFileSelect" @remove="onFileRemove" :showUploadButton="false" :showCancelButton="false" 
                                chooseLabel="Seleccionar archivos" class="w-full" />
                    <small class="text-gray-500 mt-1 block">Puedes seleccionar múltiples archivos. Máx 20MB por archivo.</small>
                    <small v-if="form.errors.media" class="text-red-500 block mt-1">{{ form.errors.media }}</small>
                </div>
            </div>

            <!-- Banner Invasivo -->
            <div class="flex flex-col gap-4 md:col-span-2 bg-purple-50 dark:bg-purple-900/20 p-4 rounded-lg border border-purple-100 dark:border-purple-800">
                <div class="flex items-center gap-3">
                    <ToggleSwitch inputId="is_banner" v-model="form.is_banner" />
                    <div>
                        <label for="is_banner" class="font-bold text-gray-800 dark:text-gray-200 block cursor-pointer">Mostrar como banner invasivo</label>
                        <small class="text-gray-600 dark:text-gray-400">Al activar, se mostrará un banner a pantalla completa en el dashboard de los usuarios, bloqueando la interacción hasta que lo cierren.</small>
                    </div>
                </div>

                <template v-if="form.is_banner">
                    <!-- Título del banner (opcional) -->
                    <div class="flex flex-col gap-2">
                        <label for="banner_title" class="font-bold text-gray-700 dark:text-gray-200">Título del banner (opcional)</label>
                        <InputText id="banner_title" v-model="form.banner_title" placeholder="Dejar vacío para usar el título de la novedad" :class="{'p-invalid': form.errors.banner_title}" />
                        <small v-if="form.errors.banner_title" class="text-red-500">{{ form.errors.banner_title }}</small>
                    </div>

                    <!-- Imagen del banner -->
                    <div class="flex flex-col gap-2">
                        <label class="font-bold text-gray-700 dark:text-gray-200">Imagen del banner</label>
                        <small class="text-gray-500">Esta imagen se mostrará a pantalla completa. Recomendado: 1200×800px o proporción similar.</small>

                        <!-- Imagen existente (edición) -->
                        <div v-if="existingBannerUrl && !form.deleted_banner" class="relative group w-max">
                            <img :src="existingBannerUrl" class="w-64 h-40 object-cover rounded-lg shadow-sm border border-gray-300 dark:border-gray-600" />
                            <button type="button" @click="onBannerRemove" class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center hover:bg-red-600 shadow-md" v-tooltip.top="'Eliminar banner'">
                                <i class="pi pi-times text-xs"></i>
                            </button>
                        </div>

                        <!-- Preview de nueva imagen -->
                        <div v-if="bannerPreviewUrl" class="relative group w-max">
                            <img :src="bannerPreviewUrl" class="w-64 h-40 object-cover rounded-lg shadow-sm border border-purple-300 dark:border-purple-600 ring-2 ring-purple-400" />
                            <button type="button" @click="onBannerRemove" class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center hover:bg-red-600 shadow-md" v-tooltip.top="'Quitar imagen'">
                                <i class="pi pi-times text-xs"></i>
                            </button>
                        </div>

                        <!-- Subir nueva imagen -->
                        <div v-if="!bannerPreviewUrl && !(existingBannerUrl && !form.deleted_banner)" class="mt-1">
                            <FileUpload name="banner_image" mode="advanced" accept="image/*" :maxFileSize="10000000" 
                                        @select="onBannerSelect" :showUploadButton="false" :showCancelButton="false" 
                                        chooseLabel="Seleccionar imagen del banner" class="w-full" />
                            <small class="text-gray-500 mt-1 block">Máx 10MB. Formatos: JPG, PNG.</small>
                        </div>
                        <small v-if="form.errors.banner_image" class="text-red-500">{{ form.errors.banner_image }}</small>
                    </div>
                </template>
            </div>

            <!-- Toggle de Publicación -->
            <div class="flex items-center gap-3 md:col-span-2 bg-blue-50 dark:bg-blue-900/20 p-4 rounded-lg border border-blue-100 dark:border-blue-800">
                <ToggleSwitch inputId="is_published" v-model="form.is_published" />
                <div>
                    <label for="is_published" class="font-bold text-gray-800 dark:text-gray-200 block cursor-pointer">Publicar Inmediatamente</label>
                    <small class="text-gray-600 dark:text-gray-400">Si lo activas, los usuarios verán la notificación al guardar. Si lo desactivas, se guardará como borrador.</small>
                </div>
            </div>
        </div>

        <!-- Acciones -->
        <div class="flex justify-end gap-3 mt-4">
            <Button label="Cancelar" icon="pi pi-times" severity="secondary" outlined @click="() => $inertia.visit(route('admin.release-notes.index'))" :disabled="form.processing" />
            <Button :label="isEditing ? 'Guardar Cambios' : 'Crear Novedad'" icon="pi pi-save" type="submit" :loading="form.processing" />
        </div>
    </form>
</template>