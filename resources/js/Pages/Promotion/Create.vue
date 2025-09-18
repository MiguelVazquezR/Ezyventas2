<script setup>
import { ref, watch } from 'vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    product: Object,
    otherProducts: Array,
});

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Productos', url: route('products.index') },
    { label: props.product.name, url: route('products.show', props.product.id) },
    { label: 'Crear Promoción' }
]);

const promotionType = ref('ITEM_DISCOUNT');
const promotionTypes = ref([
    { label: 'Descuento en este producto', value: 'ITEM_DISCOUNT' },
    { label: 'Compra X, llévate Y (BOGO)', value: 'BOGO' },
    { label: 'Paquete / Combo', value: 'BUNDLE_PRICE' },
]);

const form = useForm({
    name: '',
    description: '',
    start_date: null,
    end_date: null,
    is_active: true,
    type: 'ITEM_DISCOUNT',
    effect_type: 'PERCENTAGE_DISCOUNT',
    effect_value: null,
    required_product_id: props.product.id,
    required_quantity: 1,
    free_product_id: props.product.id,
    free_quantity: 1,
    bundle_products: [props.product.id],
    bundle_price: null,
});

// --- MEJORA: Limpiar campos del formulario al cambiar de tipo de promoción ---
watch(promotionType, (newType) => {
    form.clearErrors();
    // Resetear los campos a sus valores por defecto o nulos
    form.effect_type = 'PERCENTAGE_DISCOUNT';
    form.effect_value = null;
    form.required_product_id = props.product.id;
    form.required_quantity = 1;
    form.free_product_id = props.product.id;
    form.free_quantity = 1;
    form.bundle_products = [props.product.id];
    form.bundle_price = null;
});

const submit = () => {
    form.type = promotionType.value;
    form.post(route('products.promotions.store', props.product.id));
};

</script>

<template>

    <Head title="Crear Promoción" />
    <AppLayout>
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0 mb-4" />
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200 mb-6">Crear Promoción para: {{ product.name }}
        </h1>

        <form @submit.prevent="submit" class="max-w-2xl mx-auto">
            <!-- Información General -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                <h2 class="text-lg font-semibold border-b pb-3 mb-4">Información General</h2>
                <div class="space-y-4">
                    <div>
                        <InputLabel for="name" value="Nombre de la promoción *" />
                        <InputText id="name" v-model="form.name" class="mt-1 w-full" />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div>
                        <InputLabel for="description" value="Descripción" />
                        <Textarea id="description" v-model="form.description" rows="3" class="mt-1 w-full" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel for="start_date" value="Fecha de inicio" />
                            <Calendar id="start_date" v-model="form.start_date" showTime hourFormat="12"
                                class="w-full mt-1" />
                        </div>
                        <div>
                            <InputLabel for="end_date" value="Fecha de fin" />
                            <Calendar id="end_date" v-model="form.end_date" showTime hourFormat="12"
                                class="w-full mt-1" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tipo y Configuración de Promoción -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                <h2 class="text-lg font-semibold border-b pb-3 mb-4">Tipo de Promoción</h2>
                <Select v-model="promotionType" :options="promotionTypes" optionLabel="label" optionValue="value"
                    class="w-full" />

                <!-- Configuración para Descuento -->
                <div v-if="promotionType === 'ITEM_DISCOUNT'" class="mt-6 space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <Select v-model="form.effect_type"
                            :options="[{ label: 'Porcentaje (%)', value: 'PERCENTAGE_DISCOUNT' }, { label: 'Monto Fijo ($)', value: 'FIXED_DISCOUNT' }]"
                            class="w-full" />
                        <InputNumber v-model="form.effect_value"
                            :placeholder="form.effect_type === 'PERCENTAGE_DISCOUNT' ? '% Descuento' : '$ Descuento'"
                            class="w-full" />
                    </div>
                    <InputError :message="form.errors.effect_type" />
                    <InputError :message="form.errors.effect_value" />
                </div>

                <!-- Configuración para BOGO -->
                <div v-if="promotionType === 'BOGO'" class="mt-6 space-y-4">
                    <h3 class="font-semibold">Regla: "Compra..."</h3>
                    <div class="flex items-center gap-4">
                        <span>Compra</span>
                        <InputNumber v-model="form.required_quantity" :min="1" class="w-24" />
                        <span>de</span>
                        <Select v-model="form.required_product_id" :options="[product, ...otherProducts]"
                            optionLabel="name" optionValue="id" class="flex-grow" filter />
                    </div>
                    <h3 class="font-semibold">Efecto: "... y llévate"</h3>
                    <div class="flex items-center gap-4">
                        <span>Llévate</span>
                        <InputNumber v-model="form.free_quantity" :min="1" class="w-24" />
                        <span>de</span>
                        <Select v-model="form.free_product_id" :options="[product, ...otherProducts]" optionLabel="name"
                            optionValue="id" class="flex-grow" filter />
                        <span>gratis.</span>
                    </div>
                </div>

                <!-- Configuración para Paquete -->
                <div v-if="promotionType === 'BUNDLE_PRICE'" class="mt-6 space-y-4">
                    <h3 class="font-semibold">Regla: "Productos en el paquete"</h3>
                    <MultiSelect v-model="form.bundle_products" :options="[product, ...otherProducts]"
                        optionLabel="name" optionValue="id" placeholder="Selecciona los productos del combo"
                        class="w-full" filter />
                    <h3 class="font-semibold">Efecto: "Precio final del paquete"</h3>
                    <InputNumber v-model="form.bundle_price" mode="currency" currency="MXN" locale="es-MX"
                        placeholder="Precio del paquete" />
                    <InputError :message="form.errors.bundle_products" />
                    <InputError :message="form.errors.bundle_price" />
                </div>
            </div>

            <div class="flex justify-end">
                <Button type="submit" label="Crear Promoción" :loading="form.processing" severity="warning" />
            </div>
        </form>
    </AppLayout>
</template>