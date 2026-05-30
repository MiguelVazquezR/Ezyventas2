<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Textarea from 'primevue/textarea';
import ToggleSwitch from 'primevue/toggleswitch';
import ColorPicker from 'primevue/colorpicker';
import FileUpload from 'primevue/fileupload';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import Message from 'primevue/message';

const props = defineProps({
    storeConfig: Object,
    storeUrl: String,
});

const toast = useToast();

const form = useForm({
    slug: props.storeConfig.slug || '',
    is_active: props.storeConfig.is_active || false,
    store_name: props.storeConfig.store_name || '',
    description: props.storeConfig.description || '',
    logo: null,
    primary_color: props.storeConfig.primary_color || '#3B82F6',
    secondary_color: props.storeConfig.secondary_color || '#1D4ED8',
    welcome_message: props.storeConfig.welcome_message || '',
    accepts_pickup: props.storeConfig.accepts_pickup ?? true,
    accepts_delivery: props.storeConfig.accepts_delivery ?? true,
    delivery_fee: props.storeConfig.delivery_fee || 0,
    preparation_time_minutes: props.storeConfig.preparation_time_minutes || 30,
    delivery_policy: props.storeConfig.delivery_policy || '',
    footer_note: props.storeConfig.footer_note || '',
});

function submit() {
    form.put(route('online-store.config.update'), {
        onSuccess: () => toast.add({ severity: 'success', summary: 'Saved', detail: 'Store configuration saved.', life: 3000 }),
    });
}

function onLogoSelect(event) {
    form.logo = event.files[0];
}

const inputPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors' }
};
</script>

<template>
    <Head title="Store Configuration" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-4xl mx-auto space-y-6">
            <Toast />
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                <div class="mb-8">
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">Store configuration</h1>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full" :class="storeConfig.is_active ? 'bg-teal-500 shadow-[0_0_8px_rgba(20,184,166,0.8)] animate-pulse' : 'bg-gray-400'" />
                        {{ storeConfig.is_active ? 'Your store is live' : 'Your store is inactive' }}
                    </p>
                </div>

                <form @submit.prevent="submit" class="space-y-8">
                    <!-- Activation & Store URL -->
                    <section class="space-y-4">
                        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 m-0 pb-2 border-b border-gray-100 dark:border-[#3a3a3a]">Visibility</h2>
                        <div class="flex flex-col sm:flex-row sm:items-center gap-4 p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl">
                            <div class="flex-1">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Store status</label>
                                <p class="text-xs text-gray-400 m-0 mt-1">Activate to make your store publicly visible.</p>
                            </div>
                            <ToggleSwitch v-model="form.is_active" />
                        </div>
                        <div v-if="storeUrl" class="p-4 bg-green-50 dark:bg-green-900/10 rounded-2xl border border-green-100 dark:border-green-900/30 flex items-center gap-3">
                            <i class="pi pi-link text-green-600" />
                            <span class="text-sm text-green-800 dark:text-green-300 break-all">{{ storeUrl }}</span>
                        </div>
                    </section>

                    <!-- Basic Info -->
                    <section class="space-y-4">
                        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 m-0 pb-2 border-b border-gray-100 dark:border-[#3a3a3a]">Basic information</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Store name *</label>
                                <InputText v-model="form.store_name" :pt="inputPt" class="w-full" />
                                <Message v-if="form.errors.store_name" severity="error" variant="simple" size="small">{{ form.errors.store_name }}</Message>
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">URL slug *</label>
                                <InputText v-model="form.slug" :pt="inputPt" class="w-full" placeholder="my-shop" />
                                <Message v-if="form.errors.slug" severity="error" variant="simple" size="small">{{ form.errors.slug }}</Message>
                            </div>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Description</label>
                            <Textarea v-model="form.description" :pt="inputPt" rows="3" class="w-full" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Welcome message</label>
                            <InputText v-model="form.welcome_message" :pt="inputPt" class="w-full" placeholder="Welcome to our store!" />
                        </div>
                    </section>

                    <!-- Branding -->
                    <section class="space-y-4">
                        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 m-0 pb-2 border-b border-gray-100 dark:border-[#3a3a3a]">Branding</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Primary color</label>
                                <div class="flex items-center gap-3">
                                    <ColorPicker v-model="form.primary_color" :pt="{ input: { class: '!w-10 !h-10 !rounded-xl' } }" />
                                    <InputText v-model="form.primary_color" :pt="inputPt" class="w-32 !font-mono" />
                                </div>
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Secondary color</label>
                                <div class="flex items-center gap-3">
                                    <ColorPicker v-model="form.secondary_color" :pt="{ input: { class: '!w-10 !h-10 !rounded-xl' } }" />
                                    <InputText v-model="form.secondary_color" :pt="inputPt" class="w-32 !font-mono" />
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Logo</label>
                            <div class="flex items-center gap-4">
                                <img v-if="props.storeConfig.logo_url" :src="props.storeConfig.logo_url" class="h-16 w-16 object-contain rounded-xl bg-gray-50 dark:bg-[#1a1a1a] border border-gray-100 dark:border-[#3a3a3a]" />
                                <FileUpload mode="basic" accept="image/*" :maxFileSize="2000000" customUpload auto @select="onLogoSelect" chooseLabel="Select logo" :pt="{ chooseButton: { class: '!rounded-xl' } }" />
                            </div>
                        </div>
                    </section>

                    <!-- Delivery & Footer -->
                    <section class="space-y-4">
                        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 m-0 pb-2 border-b border-gray-100 dark:border-[#3a3a3a]">Delivery options</h2>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                            <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl">
                                <div>
                                    <span class="text-sm font-medium dark:text-white">Accept pickup in store</span>
                                    <p class="text-xs text-gray-400 m-0">Customers can collect orders at your location.</p>
                                </div>
                                <ToggleSwitch v-model="form.accepts_pickup" />
                            </div>
                            <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl">
                                <div>
                                    <span class="text-sm font-medium dark:text-white">Accept delivery</span>
                                    <p class="text-xs text-gray-400 m-0">Ship orders to customer addresses.</p>
                                </div>
                                <ToggleSwitch v-model="form.accepts_delivery" />
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Delivery fee</label>
                                <InputNumber v-model="form.delivery_fee" mode="currency" currency="MXN" :pt="{ input: { root: { class: 'w-full !rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-sm' } } }" />
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Preparation time (min)</label>
                                <InputNumber v-model="form.preparation_time_minutes" :pt="{ input: { root: { class: 'w-full !rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-sm' } } }" />
                            </div>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Delivery policy</label>
                            <Textarea v-model="form.delivery_policy" :pt="inputPt" rows="3" class="w-full" />
                        </div>
                    </section>

                    <!-- Footer -->
                    <section class="space-y-4">
                        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 m-0 pb-2 border-b border-gray-100 dark:border-[#3a3a3a]">Footer</h2>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Footer note</label>
                            <InputText v-model="form.footer_note" :pt="inputPt" class="w-full" placeholder="© 2026 My Store. All rights reserved." />
                        </div>
                    </section>

                    <div class="flex justify-end pt-4 border-t border-gray-100 dark:border-[#3a3a3a]">
                        <Button type="submit" :loading="form.processing" label="Save changes" icon="pi pi-check" class="!rounded-full" />
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
