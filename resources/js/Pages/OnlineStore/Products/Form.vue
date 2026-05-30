<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import Button from 'primevue/button';
import InputNumber from 'primevue/inputnumber';
import Textarea from 'primevue/textarea';
import ToggleSwitch from 'primevue/toggleswitch';
import FileUpload from 'primevue/fileupload';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import Message from 'primevue/message';

const props = defineProps({
    product: Object,
});

const toast = useToast();

const formatCurrency = (num) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(num || 0);
};

const form = useForm({
    online_price: props.product?.online_price || null,
    show_online: props.product?.show_online ?? false,
    is_featured: props.product?.is_featured ?? false,
    store_sort_order: props.product?.store_sort_order || 0,
    description: props.product?.description || '',
    image: null,
});

function submit() {
    form.put(route('online-store.products.update', props.product.id), {
        onSuccess: () => toast.add({ severity: 'success', summary: 'Saved', detail: 'Store settings updated.', life: 3000 }),
    });
}

function onImageSelect(event) {
    form.image = event.files[0];
}
</script>

<template>
    <Head title="Edit Store Product" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 max-w-2xl mx-auto space-y-6">
            <Toast />
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                <div class="mb-8">
                    <h1 class="text-3xl font-light tracking-tight text-gray-900 dark:text-white m-0">{{ product.name }}</h1>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2">Configure how this product appears in your online store</p>
                </div>

                <!-- Current POS info -->
                <div class="bg-gray-50 dark:bg-[#1a1a1a] p-4 rounded-2xl mb-6 grid grid-cols-2 gap-4">
                    <div>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">POS price</span>
                        <p class="font-mono text-lg font-semibold dark:text-white m-0">{{ formatCurrency(product.selling_price) }}</p>
                    </div>
                    <div>
                        <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500">SKU</span>
                        <p class="font-mono text-sm dark:text-white m-0">{{ product.sku || 'N/A' }}</p>
                    </div>
                </div>

                <form @submit.prevent="submit" class="space-y-6">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Online price</label>
                        <p class="text-xs text-gray-400 m-0">Leave empty to use the POS price.</p>
                        <InputNumber v-model="form.online_price" mode="currency" currency="MXN" :pt="{ input: { root: { class: 'w-full !rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-sm' } } }" />
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Description for online store</label>
                        <Textarea v-model="form.description" :pt="{ root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a]' } }" rows="3" class="w-full" />
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sort order</label>
                        <InputNumber v-model="form.store_sort_order" :pt="{ input: { root: { class: 'w-24 !rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-sm' } } }" />
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Image</label>
                        <div class="flex items-center gap-3">
                            <img v-if="product.media?.length" :src="product.media[0].original_url" class="h-12 w-12 object-cover rounded-lg" />
                            <FileUpload mode="basic" accept="image/*" :maxFileSize="2000000" customUpload auto @select="onImageSelect" chooseLabel="Change image" :pt="{ chooseButton: { class: '!rounded-xl !text-xs' } }" />
                        </div>
                    </div>

                    <div class="space-y-4 p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <span class="text-sm font-medium dark:text-white">Show in online store</span>
                                <p class="text-xs text-gray-400 m-0">Make this product visible to customers.</p>
                            </div>
                            <ToggleSwitch v-model="form.show_online" />
                        </div>
                        <div class="flex items-center justify-between">
                            <div>
                                <span class="text-sm font-medium dark:text-white">Featured product</span>
                                <p class="text-xs text-gray-400 m-0">Show in special positions on your store.</p>
                            </div>
                            <ToggleSwitch v-model="form.is_featured" />
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 pt-4 border-t border-gray-100 dark:border-[#3a3a3a]">
                        <Button as="a" :href="route('online-store.products.index')" label="Cancel" severity="secondary" outlined class="!rounded-full" />
                        <Button type="submit" :loading="form.processing" label="Save changes" icon="pi pi-check" class="!rounded-full" />
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
