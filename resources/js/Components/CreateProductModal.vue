<script setup>
import { ref } from 'vue';
import axios from 'axios';
import { useToast } from 'primevue/usetoast';
import InputLabel from './InputLabel.vue';
import InputError from './InputError.vue';

defineProps({
    visible: Boolean,
});
const emit = defineEmits(['update:visible', 'created']);

const toast = useToast();

const form = ref({
    name: '',
    sku: '',
    selling_price: null,
    current_stock: 1,
});
const errors = ref({});
const processing = ref(false);

const closeModal = () => {
    emit('update:visible', false);
    form.value = { name: '', sku: '', selling_price: null, current_stock: null };
    errors.value = {};
};

const submit = async () => {
    processing.value = true;
    errors.value = {};

    try {
        // CAMBIO: Se captura la respuesta del servidor
        const response = await axios.post(route('quick-create.products.store'), form.value);
        toast.add({ severity: 'success', summary: 'Éxito', detail: 'Producto creado correctamente.', life: 7000 });
        // CAMBIO: Se emite el evento 'created' con el nuevo producto como payload
        emit('created', response.data);
        closeModal();
    } catch (error) {
        if (error.response && error.response.status === 422) {
            errors.value = error.response.data.errors;
            toast.add({ severity: 'error', summary: 'Error de Validación', detail: 'Por favor, revisa los campos.', life: 7000 });
        } else {
            console.error("Error al crear producto:", error);
            toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudo crear el producto.', life: 7000 });
        }
    } finally {
        processing.value = false;
    }
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Crear Nuevo Producto" :style="{ width: '30rem' }">
        <form @submit.prevent="submit" class="p-2 space-y-4">
            <div>
                <InputLabel for="product-name" value="Nombre del Producto *" />
                <InputText id="product-name" v-model="form.name" class="w-full mt-1" />
                <InputError :message="errors.name ? errors.name[0] : ''" class="mt-1" />
            </div>
            <div class="grid grid-cols-2 gap-4">
                 <div>
                    <InputLabel for="product-sku" value="SKU" />
                    <InputText id="product-sku" v-model="form.sku" class="w-full mt-1" />
                    <InputError :message="errors.sku ? errors.sku[0] : ''" class="mt-1" />
                </div>
                 <div>
                    <InputLabel for="product-stock" value="Stock Inicial *" />
                    <InputNumber id="product-stock" v-model="form.current_stock" class="w-full mt-1" inputClass="w-full" />
                    <InputError :message="errors.current_stock ? errors.current_stock[0] : ''" class="mt-1" />
                </div>
            </div>
            <div>
                <InputLabel for="product-price" value="Precio de Venta *" />
                <InputNumber id="product-price" v-model="form.selling_price" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" inputClass="w-full" />
                <InputError :message="errors.selling_price ? errors.selling_price[0] : ''" class="mt-1" />
            </div>
            <div class="flex justify-end gap-2 mt-4">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal" text></Button>
                <Button type="submit" label="Guardar Producto" :loading="processing"></Button>
            </div>
        </form>
    </Dialog>
</template>