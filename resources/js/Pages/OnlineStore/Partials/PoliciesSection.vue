<script setup>
defineProps({
    form: { type: Object, required: true },
    inputPt: { type: Object, required: true },
    hasRealContent: { type: Function, required: true },
    loadPolicyTemplate: { type: Function, required: true },
});
</script>

<template>
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
</template>
