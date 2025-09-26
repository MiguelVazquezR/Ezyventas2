<script setup>
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    visible: Boolean,
    products: Array,
});

const emit = defineEmits(['update:visible']);

const form = useForm({
    products: [],
});

// SOLUCIÓN: Usar un watcher sobre la prop `visible` para poblar el formulario
// cada vez que el modal se hace visible.
watch(() => props.visible, (isVisible) => {
    if (isVisible) {
        form.products = props.products.map(p => ({
            id: p.id,
            name: p.name,
            current_stock: p.current_stock,
            quantity: 1,
        }));
    }
});

const closeModal = () => {
    emit('update:visible', false);
    // No reseteamos el form aquí para evitar problemas, el watcher se encargará
};

const submit = () => {
    form.post(route('products.stock.batchStore'), {
        onSuccess: () => closeModal(),
        preserveScroll: true,
    });
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Dar Entrada a Productos" :style="{ width: '40rem' }">
        <form @submit.prevent="submit" class="p-2">
            <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">Ingresa la cantidad a agregar al stock actual de cada producto seleccionado.</p>
            <div class="space-y-3 max-h-96 overflow-y-auto pr-2 border-y py-2 dark:border-gray-700">
                <div v-for="(product, index) in form.products" :key="product.id" class="grid grid-cols-3 items-center gap-4">
                     <div class="col-span-2">
                        <p class="font-semibold truncate">{{ product.name }}</p>
                        <p class="text-xs text-gray-500">Stock actual: {{ product.current_stock }}</p>
                     </div>
                    <InputNumber v-model="product.quantity" :min="0" inputClass="w-full text-center" />
                </div>
            </div>
             <InputError :message="form.errors.products" class="mt-2" />

            <div class="flex justify-end gap-2 mt-6">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal"></Button>
                <Button type="submit" label="Confirmar Entrada" :loading="form.processing"></Button>
            </div>
        </form>
    </Dialog>
</template>