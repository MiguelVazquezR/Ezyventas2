<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';

const props = defineProps({
    visible: Boolean,
    product: Object,
});

const emit = defineEmits(['update:visible']);

const isVariantProduct = computed(() => props.product.product_attributes && props.product.product_attributes.length > 0);

const form = useForm({
    type: isVariantProduct.value ? 'variant' : 'simple',
    quantity: 1,
    variants: isVariantProduct.value 
        ? props.product.product_attributes.map(v => ({ id: v.id, attributes: v.attributes, quantity: 0 })) 
        : [],
});

const closeModal = () => {
    emit('update:visible', false);
    form.reset();
};

const submit = () => {
    form.post(route('products.stock.store', props.product.id), {
        onSuccess: () => closeModal(),
    });
};

</script>

<template>
    <Dialog :visible="visible" @update:visible="closeModal" modal header="Dar entrada a producto" :style="{ width: '35rem' }">
        <form @submit.prevent="submit" class="p-2">
            <div v-if="!isVariantProduct">
                <InputLabel for="quantity" value="Cantidad a agregar" />
                <InputNumber id="quantity" v-model="form.quantity" class="w-full mt-1" :min="1" />
                <InputError :message="form.errors.quantity" class="mt-2" />
            </div>

            <div v-else>
                <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">Ingresa la cantidad a agregar para cada variante:</p>
                <div class="space-y-3 max-h-64 overflow-y-auto pr-2">
                    <div v-for="(variant, index) in form.variants" :key="variant.id" class="flex items-center justify-between">
                         <div>
                            <span v-for="(value, key) in variant.attributes" :key="key" class="mr-2">
                                <span class="font-semibold">{{ key }}:</span> {{ value }}
                            </span>
                         </div>
                        <InputNumber v-model="variant.quantity" :min="0" inputClass="w-20 text-center" />
                    </div>
                </div>
                 <InputError :message="form.errors.variants" class="mt-1" />
            </div>

            <div class="flex justify-end gap-2 mt-6">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal"></Button>
                <Button type="submit" label="Confirmar entrada" :loading="form.processing"></Button>
            </div>
        </form>
    </Dialog>
</template>