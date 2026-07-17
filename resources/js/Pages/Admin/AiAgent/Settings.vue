<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';
import Button from 'primevue/button';
import Card from 'primevue/card';
import Message from 'primevue/message';
import Divider from 'primevue/divider';

const props = defineProps({
    settings: Object,
});

const form = useForm({
    ai_provider: props.settings['ai.provider']?.value ?? 'deepseek',
    ai_model: props.settings['ai.model']?.value ?? 'deepseek-v4-flash',
    ai_api_key: props.settings['ai.api_key']?.value ?? '',
    ai_token_limit: parseInt(props.settings['ai.token_limit']?.value ?? 2000000),
});

const providerOptions = [
    { label: 'DeepSeek', value: 'deepseek' },
    { label: 'Anthropic', value: 'anthropic' },
    { label: 'OpenAI', value: 'openai' },
    { label: 'Groq', value: 'groq' },
    { label: 'Ollama', value: 'ollama' },
];

function submit() {
    form.put(route('admin.ai-agent.update'), {
        preserveScroll: true,
    });
}
</script>

<template>
    <AppLayout title="Configuración del Asistente IA">
        <div class="p-4 md:p-6 lg:p-8 max-w-3xl mx-auto space-y-6">
            <div>
                <h1 class="text-3xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                    Asistente IA
                </h1>
                <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 mt-1">
                    Configuración global de la plataforma
                </p>
            </div>

            <Card :pt="{ root: { class: '!bg-white dark:!bg-[#232323] !rounded-3xl !border !border-gray-100 dark:!border-[#3a3a3a]' }, body: { class: '!p-6' }, content: { class: '!p-0' } }">
                <template #title>
                    <span class="text-xs uppercase tracking-widest font-bold text-gray-500">Proveedor y modelo</span>
                </template>
                <template #content>
                    <div class="space-y-4">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Proveedor *</label>
                            <Select v-model="form.ai_provider" :options="providerOptions" optionLabel="label" optionValue="value"
                                class="w-full"
                                :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }" />
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Modelo *</label>
                            <InputText v-model="form.ai_model"
                                class="w-full"
                                :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }" />
                        </div>
                    </div>
                </template>
            </Card>

            <Card :pt="{ root: { class: '!bg-white dark:!bg-[#232323] !rounded-3xl !border !border-gray-100 dark:!border-[#3a3a3a]' }, body: { class: '!p-6' }, content: { class: '!p-0' } }">
                <template #title>
                    <span class="text-xs uppercase tracking-widest font-bold text-gray-500">API Key</span>
                </template>
                <template #content>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Clave de API del proveedor</label>
                        <InputText v-model="form.ai_api_key" type="password"
                            placeholder="Dejar vacío para no modificar"
                            class="w-full"
                            :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }" />
                        <p class="text-[10px] text-gray-400 m-0">La clave se almacena encriptada. Déjala vacía para conservar la actual.</p>
                    </div>
                </template>
            </Card>

            <Card :pt="{ root: { class: '!bg-white dark:!bg-[#232323] !rounded-3xl !border !border-gray-100 dark:!border-[#3a3a3a]' }, body: { class: '!p-6' }, content: { class: '!p-0' } }">
                <template #title>
                    <span class="text-xs uppercase tracking-widest font-bold text-gray-500">Límite mensual de tokens</span>
                </template>
                <template #content>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Tokens por mes (global)</label>
                        <InputNumber
                            :modelValue="form.ai_token_limit"
                            @update:modelValue="(val) => form.ai_token_limit = val"
                            :min="0" :step="500000"
                            class="w-full"
                            :pt="{
                                input: { root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] !py-2.5 !text-lg !font-light' } }
                            }" />
                        <p class="text-[10px] text-gray-400 m-0">Aplica a todas las suscripciones con el módulo activo. Por defecto: 2,000,000.</p>
                    </div>
                </template>
            </Card>

            <div class="flex justify-end">
                <Button
                    label="Guardar cambios"
                    icon="pi pi-check"
                    :loading="form.processing"
                    class="!rounded-full"
                    @click="submit"
                />
            </div>
        </div>
    </AppLayout>
</template>
