<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';

/**
 * Create/edit form for one help center guide (Admin → Centro de ayuda).
 *
 * Guides are written in HTML from this modal and rendered inside the module
 * page of the help center next to that module's videos.
 */
const props = defineProps({
    moduleOptions: { type: Array, default: () => [] },
});

const emit = defineEmits(['saved']);

const visible = ref(false);
const editing = ref(null);
const showPreview = ref(false);

const form = useForm({
    module: null,
    title: '',
    excerpt: '',
    content: '',
    is_published: true,
});

const isEditing = computed(() => editing.value !== null);

const moduleSelectOptions = computed(() =>
    props.moduleOptions.map((option) => ({ label: option.label, value: option.key }))
);

function open(article = null, moduleKey = null) {
    editing.value = article ?? null;
    showPreview.value = false;

    form.clearErrors();
    form.reset();

    if (article) {
        form.module = article.module;
        form.title = article.title;
        form.excerpt = article.excerpt || '';
        form.content = article.content || '';
        form.is_published = article.is_published;
    } else {
        form.module = moduleKey;
        form.is_published = true;
    }

    visible.value = true;
}

defineExpose({ open });

const submit = () => {
    const options = {
        onSuccess: () => {
            visible.value = false;
            emit('saved');
        },
    };

    if (isEditing.value) {
        form.put(route('admin.help-articles.update', editing.value.id), options);
    } else {
        form.post(route('admin.help-articles.store'), options);
    }
};

// ──────────────────────────────────────
// Tesla UI Pass-Through configurations
// ──────────────────────────────────────
const dialogPt = {
    root: { class: 'dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] rounded-3xl shadow-2xl overflow-hidden' },
    header: { class: 'bg-gray-50 dark:bg-[#1a1a1a] border-b border-gray-100 dark:border-[#3a3a3a] px-5 py-4' },
    content: { class: 'p-0 dark:bg-[#232323]' },
};

const inputPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !text-sm' },
};

const textareaPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !text-sm' },
};
</script>

<template>
    <Dialog v-model:visible="visible" modal :style="{ width: '52rem', maxWidth: '95vw' }" :pt="dialogPt">
        <template #header>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-primary-500/10 flex items-center justify-center">
                    <i class="pi pi-file-edit text-primary-500 !text-lg"></i>
                </div>
                <div>
                    <h3 class="text-base font-medium text-gray-900 dark:text-white m-0">
                        {{ isEditing ? 'Editar guía' : 'Nueva guía' }}
                    </h3>
                    <p class="text-xs uppercase tracking-widest text-gray-500 m-0">
                        {{ isEditing ? 'Actualiza el contenido de la guía' : 'Documenta un módulo del sistema' }}
                    </p>
                </div>
            </div>
        </template>

        <div class="flex flex-col gap-4 px-5 py-5 max-h-[70vh] overflow-y-auto">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Module -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Módulo *</label>
                    <Select
                        v-model="form.module"
                        :options="moduleSelectOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Selecciona un módulo"
                        filter
                        :pt="inputPt"
                    />
                    <Message v-if="form.errors.module" severity="error" variant="simple" size="small">{{ form.errors.module }}</Message>
                </div>

                <!-- Published -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Visibilidad</label>
                    <div class="flex items-center gap-3 h-full">
                        <ToggleSwitch v-model="form.is_published" />
                        <span class="text-sm text-gray-600 dark:text-gray-400">
                            {{ form.is_published ? 'Visible en el centro de ayuda' : 'Oculta para los usuarios' }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Title -->
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Título *</label>
                <InputText v-model="form.title" placeholder="Ej. Cómo registrar una venta" :pt="inputPt" />
                <Message v-if="form.errors.title" severity="error" variant="simple" size="small">{{ form.errors.title }}</Message>
            </div>

            <!-- Excerpt -->
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Resumen</label>
                <InputText v-model="form.excerpt" placeholder="Una frase que explique de qué trata la guía" :pt="inputPt" />
                <Message v-if="form.errors.excerpt" severity="error" variant="simple" size="small">{{ form.errors.excerpt }}</Message>
            </div>

            <!-- Content -->
            <div class="flex flex-col gap-1.5">
                <div class="flex items-center justify-between gap-2">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Contenido *</label>
                    <Button
                        :label="showPreview ? 'Editar' : 'Vista previa'"
                        :icon="showPreview ? 'pi pi-pencil' : 'pi pi-eye'"
                        text
                        size="small"
                        class="!text-[10px] !uppercase !tracking-widest !font-bold"
                        @click="showPreview = !showPreview"
                    />
                </div>

                <div
                    v-if="showPreview"
                    class="min-h-40 p-4 rounded-xl border border-gray-200 dark:border-[#3a3a3a] bg-white dark:bg-[#1a1a1a] prose prose-sm max-w-none dark:prose-invert"
                    v-html="form.content || '<p class=&quot;text-gray-400&quot;>Sin contenido.</p>'"
                ></div>
                <Textarea
                    v-else
                    v-model="form.content"
                    rows="10"
                    autoResize
                    placeholder="<p>Escribe aquí los pasos de la guía...</p>"
                    :pt="textareaPt"
                />

                <Message v-if="form.errors.content" severity="error" variant="simple" size="small">{{ form.errors.content }}</Message>
                <span class="text-[10px] text-gray-400 dark:text-gray-500">
                    Acepta HTML básico: párrafos, listas, negritas, enlaces e imágenes.
                </span>
            </div>
        </div>

        <template #footer>
            <div class="flex flex-wrap justify-end gap-2">
                <Button
                    label="Cancelar"
                    severity="secondary"
                    outlined
                    class="!rounded-full !uppercase !tracking-widest !text-xs !font-bold"
                    @click="visible = false"
                />
                <Button
                    :label="isEditing ? 'Guardar cambios' : 'Crear guía'"
                    icon="pi pi-check"
                    :loading="form.processing"
                    class="!rounded-full !uppercase !tracking-widest !text-xs !font-bold"
                    @click="submit"
                />
            </div>
        </template>
    </Dialog>
</template>
