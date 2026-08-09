<script setup>
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    visible: Boolean,
    product: Object,
});

const emit = defineEmits(['update:visible']);

const hasVariants = computed(() => props.product?.product_attributes?.length > 0);

const form = useForm({
    reserved_stock: 0,
    available_stock: 0,
    variants: [],
});

watch(() => props.visible, (newVal) => {
    if (newVal && props.product) {
        form.clearErrors();
        form.reset();

        if (hasVariants.value) {
            form.variants = props.product.product_attributes.map(v => ({
                id: v.id,
                name: Object.values(v.attributes || {}).join(' '),
                reserved_stock: Number(v.reserved_stock) || 0,
                available_stock: Number(v.available_stock) || 0,
            }));
        } else {
            form.reserved_stock = Number(props.product.reserved_stock) || 0;
            form.available_stock = Number(props.product.available_stock) || 0;
        }
    }
});

const closeModal = () => emit('update:visible', false);

const submit = () => {
    form.put(route('products.layaway-stock.update', props.product.id), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
    });
};
</script>

<template>
    <Dialog :visible="visible" @update:visible="emit('update:visible', $event)" modal
        :header="'Ajustar apartados' + (hasVariants ? ' y disponible por variante' : '')"
        :style="{ width: '42rem' }"
        :breakpoints="{ '1199px': '75vw', '575px': '90vw' }">
        <form @submit.prevent="submit" class="mt-2">

            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4 flex items-start gap-2">
                <i class="pi pi-info-circle text-indigo-400 !text-base mt-0.5"></i>
                <span>Usa este ajuste solo cuando los apartados o la cantidad disponible no coincidan por algún error. La cantidad disponible se calcula como <strong>físico - apartados</strong>.</span>
            </p>

            <div v-if="!hasVariants" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Cantidad de apartados</label>
                    <InputNumber v-model="form.reserved_stock" :min="0" :maxFractionDigits="3" class="w-full"
                        :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }" />
                    <Message v-if="form.errors.reserved_stock" severity="error" variant="simple" size="small">{{ form.errors.reserved_stock }}</Message>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Cantidad disponible</label>
                    <InputNumber v-model="form.available_stock" :min="0" :maxFractionDigits="3" class="w-full"
                        :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }" />
                    <Message v-if="form.errors.available_stock" severity="error" variant="simple" size="small">{{ form.errors.available_stock }}</Message>
                </div>
            </div>

            <div v-else class="border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden">
                <div class="bg-gray-50 dark:bg-[#1a1a1a] p-3 border-b border-gray-100 dark:border-[#3a3a3a] grid grid-cols-12 gap-2 text-[10px] uppercase tracking-widest font-bold text-gray-500">
                    <div class="col-span-4">Variante</div>
                    <div class="col-span-4 text-center">Apartados</div>
                    <div class="col-span-4 text-center">Disponible</div>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-[#3a3a3a] max-h-[320px] overflow-y-auto">
                    <div v-for="v in form.variants" :key="v.id" class="grid grid-cols-12 gap-2 p-3 items-center">
                        <div class="col-span-4 text-sm font-medium text-gray-700 dark:text-gray-300 truncate">{{ v.name }}</div>
                        <div class="col-span-4">
                            <InputNumber v-model="v.reserved_stock" :min="0" :maxFractionDigits="3" class="w-full"
                                :pt="{ root: { class: '!rounded-xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }" />
                        </div>
                        <div class="col-span-4">
                            <InputNumber v-model="v.available_stock" :min="0" :maxFractionDigits="3" class="w-full"
                                :pt="{ root: { class: '!rounded-xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' } }" />
                        </div>
                    </div>
                </div>
                <Message v-if="form.errors.variants" severity="error" variant="simple" size="small" class="m-3">{{ form.errors.variants }}</Message>
            </div>

            <div class="flex justify-end gap-2 mt-6 pt-4 border-t border-gray-100 dark:border-[#3a3a3a]">
                <Button type="button" label="Cancelar" severity="secondary" @click="closeModal" text></Button>
                <Button type="submit" label="Guardar ajuste" icon="pi pi-check" :loading="form.processing" class="!rounded-full"></Button>
            </div>
        </form>
    </Dialog>
</template>