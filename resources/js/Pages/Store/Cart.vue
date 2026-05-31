<script setup>
import { ref, computed, onMounted } from 'vue';
import { Head, router, usePage, Link } from '@inertiajs/vue3';
import StoreLayout from '@/Layouts/StoreLayout.vue';
import { useToast } from 'primevue/usetoast';

const page = usePage();
const store = computed(() => page.props.store || {});
const toast = useToast();

const slug = computed(() => {
    const parts = window.location.pathname.split('/');
    return parts[2] || '';
});

const formatCurrency = (num) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(num || 0);
};

const cartItems = ref([]);

onMounted(() => {
    const stored = sessionStorage.getItem('store_cart');
    cartItems.value = stored ? JSON.parse(stored) : [];
});

const removeItem = (index) => {
    cartItems.value.splice(index, 1);
    sessionStorage.setItem('store_cart', JSON.stringify(cartItems.value));
};

const updateQuantity = (index, qty) => {
    cartItems.value[index].quantity = qty;
    sessionStorage.setItem('store_cart', JSON.stringify(cartItems.value));
};

const subtotal = computed(() => cartItems.value.reduce((sum, i) => sum + Number(i.price) * Number(i.quantity), 0));

const freeShippingMin = computed(() => Number(store.value.free_shipping_minimum) || 0);
const freeShippingReached = computed(() => freeShippingMin.value > 0 && subtotal.value >= freeShippingMin.value);
const freeShippingRemaining = computed(() => {
    if (freeShippingReached.value || freeShippingMin.value <= 0) return 0;
    return freeShippingMin.value - subtotal.value;
});

const deliveryTypes = computed(() => {
    const types = [];
    if (store.value.accepts_pickup) types.push({ label: 'Recoger en tienda', value: 'pickup' });
    if (store.value.accepts_delivery) types.push({ label: 'Envío a domicilio', value: 'delivery' });
    return types;
});

const deliveryType = ref('pickup');
const deliveryFee = computed(() => {
    if (deliveryType.value !== 'delivery') return 0;
    if (freeShippingReached.value) return 0;
    return Number(store.value.delivery_fee) || 0;
});
const total = computed(() => Number(subtotal.value) + Number(deliveryFee.value));

const getStep = (item) => item.is_bulk ? 0.1 : 1;
const getMin = (item) => item.is_bulk ? 0.1 : 1;

const form = ref({
    customer_name: '',
    customer_phone: '',
    customer_email: '',
    delivery_address: '',
    customer_notes: '',
});

const errors = ref({});
const submitting = ref(false);

const placeOrder = () => {
    errors.value = {};

    if (cartItems.value.length === 0) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Tu carrito está vacío.', life: 3000 });
        return;
    }
    if (!form.value.customer_name.trim()) {
        errors.value.customer_name = 'Este campo es obligatorio.';
        return;
    }
    if (!form.value.customer_phone.trim()) {
        errors.value.customer_phone = 'Este campo es obligatorio.';
        return;
    }
    if (deliveryType.value === 'delivery' && !form.value.delivery_address.trim()) {
        errors.value.delivery_address = 'La dirección es obligatoria para envío.';
        return;
    }

    submitting.value = true;

    router.post(route('store.order.place', { slug: slug.value }), {
        items: cartItems.value.map(i => ({ product_id: i.product_id, quantity: i.quantity })),
        customer_name: form.value.customer_name,
        customer_phone: form.value.customer_phone,
        customer_email: form.value.customer_email || undefined,
        delivery_type: deliveryType.value,
        delivery_address: form.value.delivery_address || undefined,
        customer_notes: form.value.customer_notes || undefined,
    }, {
        onError: (err) => {
            errors.value = err;
            toast.add({ severity: 'error', summary: 'Error', detail: 'Revisa el formulario e intenta de nuevo.', life: 5000 });
        },
        onFinish: () => {
            submitting.value = false;
            sessionStorage.removeItem('store_cart');
        },
    });
};

const inputPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:!border-gray-300 dark:focus:!border-gray-600 !text-gray-900 dark:!text-white transition-colors' }
};

const isEmpty = computed(() => cartItems.value.length === 0);
</script>

<template>
    <Head :title="'Carrito — ' + (store.name || 'Tienda')" />
    <StoreLayout>
        <div class="max-w-5xl mx-auto px-4 md:px-6 py-8">
            <!-- Back link -->
            <Link :href="route('store.home', { slug: slug })"
                class="inline-flex items-center gap-1.5 text-xs font-medium text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors mb-6">
                <i class="pi pi-arrow-left !text-[10px]" />
                Volver a la tienda
            </Link>

            <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white mb-8 m-0">Tu pedido</h1>

            <!-- Empty cart -->
            <div v-if="isEmpty" class="bg-white dark:bg-[#232323] rounded-3xl border border-gray-100 dark:border-[#3a3a3a] p-16 text-center">
                <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-gray-50 dark:bg-[#1a1a1a] flex items-center justify-center">
                    <i class="pi pi-shopping-cart !text-2xl text-gray-300 dark:text-gray-600" />
                </div>
                <p class="text-gray-500 dark:text-gray-400 text-lg mb-4 m-0">Tu carrito está vacío.</p>
                <Link :href="route('store.home', { slug: slug })"
                    class="inline-flex items-center gap-2 px-6 py-2.5 rounded-full text-sm font-medium text-white transition-all"
                    :style="{ background: 'var(--store-primary)' }">
                    <i class="pi pi-arrow-left !text-xs" />
                    Ver productos
                </Link>
            </div>

            <div v-else class="grid grid-cols-1 lg:grid-cols-5 gap-6">
                <!-- Cart items -->
                <div class="lg:col-span-3 space-y-3">
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 m-0 mb-2">
                        {{ cartItems.length }} {{ cartItems.length === 1 ? 'producto' : 'productos' }}
                    </p>
                    <div v-for="(item, index) in cartItems" :key="index"
                        class="bg-white dark:bg-[#232323] rounded-2xl border border-gray-100 dark:border-[#3a3a3a] p-4 flex gap-4 items-center group">
                        <div class="w-16 h-16 bg-gray-50 dark:bg-[#1a1a1a] rounded-xl flex items-center justify-center shrink-0">
                            <img v-if="item.image_url" :src="item.image_url" class="max-h-full max-w-full object-contain" />
                            <i v-else class="pi pi-image text-gray-300 dark:text-gray-600" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="font-medium text-sm text-gray-900 dark:text-white m-0 truncate">{{ item.name }}</h3>
                            <p class="text-sm font-light tracking-tight mt-0.5 m-0" :style="{ color: 'var(--store-primary)' }">
                                {{ formatCurrency(item.price) }}
                                <span v-if="item.is_bulk" class="text-[10px] text-gray-400 dark:text-gray-500">/ {{ item.measure_unit || 'unidad' }}</span>
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <InputNumber fluid v-model="item.quantity" :min="getMin(item)" :max="999" :step="getStep(item)" showButtons class="!w-[108px]"
                                @update:modelValue="updateQuantity(index, $event)"
                                :pt="{ input: { root: { class: '!rounded-xl !text-center !text-sm !bg-white dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] !text-gray-900 dark:!text-white' } } }" />
                            <Button icon="pi pi-trash" text rounded severity="danger" size="small" @click="removeItem(index)"
                                :pt="{ root: { class: '!text-gray-400 hover:!text-red-500 dark:hover:!text-red-400 transition-colors' } }" />
                        </div>
                    </div>
                </div>

                <!-- Order form -->
                <div class="lg:col-span-2 space-y-4">
                    <!-- Delivery type -->
                    <div class="bg-white dark:bg-[#232323] rounded-2xl border border-gray-100 dark:border-[#3a3a3a] p-4">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 m-0 mb-3 block">Tipo de entrega</label>
                        <SelectButton v-model="deliveryType" :options="deliveryTypes" optionLabel="label" optionValue="value" class="w-full"
                            :pt="{
                                root: { class: '!bg-transparent !border-0 !p-0' },
                                button: { class: '!text-xs !rounded-xl flex-1 !bg-white dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] !text-gray-600 dark:!text-gray-400' }
                            }" />
                    </div>

                    <!-- Customer info -->
                    <div class="bg-white dark:bg-[#232323] rounded-2xl border border-gray-100 dark:border-[#3a3a3a] p-4 space-y-3">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 m-0 block">Tu información</label>
                        <div>
                            <InputText v-model="form.customer_name" placeholder="Nombre completo *" :pt="inputPt" class="w-full" />
                            <Message v-if="errors.customer_name" severity="error" variant="simple" size="small" class="mt-1">{{ errors.customer_name }}</Message>
                        </div>
                        <div>
                            <InputText v-model="form.customer_phone" placeholder="Teléfono *" :pt="inputPt" class="w-full" />
                            <Message v-if="errors.customer_phone" severity="error" variant="simple" size="small" class="mt-1">{{ errors.customer_phone }}</Message>
                        </div>
                        <InputText v-model="form.customer_email" placeholder="Correo electrónico (opcional)" :pt="inputPt" class="w-full" />
                        <div v-if="deliveryType === 'delivery'">
                            <Textarea v-model="form.delivery_address" placeholder="Dirección de entrega *" :pt="inputPt" rows="2" class="w-full" />
                            <Message v-if="errors.delivery_address" severity="error" variant="simple" size="small" class="mt-1">{{ errors.delivery_address }}</Message>
                        </div>
                        <Textarea v-model="form.customer_notes" placeholder="Notas adicionales (opcional)" :pt="inputPt" rows="2" class="w-full" />
                    </div>

                    <!-- Totals -->
                    <div class="bg-white dark:bg-[#232323] rounded-2xl border border-gray-100 dark:border-[#3a3a3a] p-4 space-y-2">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500 dark:text-gray-400">Subtotal</span>
                            <span class="font-medium text-gray-900 dark:text-white">{{ formatCurrency(subtotal) }}</span>
                        </div>
                        <!-- Free shipping progress -->
                        <div v-if="freeShippingMin > 0 && !freeShippingReached" class="text-xs text-gray-400 dark:text-gray-500 flex items-center gap-1.5 py-1">
                            <i class="pi pi-truck !text-xs" />
                            Agrega {{ formatCurrency(freeShippingRemaining) }} más para envío gratis
                        </div>
                        <!-- Free shipping reached -->
                        <div v-if="freeShippingReached" class="text-xs text-green-600 dark:text-green-400 flex items-center gap-1.5 py-1">
                            <i class="pi pi-check-circle !text-xs" />
                            ¡Envío gratis!
                        </div>
                        <div v-if="deliveryFee > 0" class="flex justify-between text-sm">
                            <span class="text-gray-500 dark:text-gray-400">Costo de envío</span>
                            <span class="text-gray-900 dark:text-white">{{ formatCurrency(deliveryFee) }}</span>
                        </div>
                        <div v-else-if="deliveryType === 'delivery' && freeShippingReached" class="flex justify-between text-sm">
                            <span class="text-gray-500 dark:text-gray-400">Costo de envío</span>
                            <span class="text-green-600 dark:text-green-400 line-through">{{ formatCurrency(store.delivery_fee) }}</span>
                        </div>
                        <div class="flex justify-between pt-2 border-t border-gray-100 dark:border-[#3a3a3a]">
                            <span class="text-base font-semibold text-gray-900 dark:text-white">Total</span>
                            <span class="text-xl font-light tracking-tight" :style="{ color: 'var(--store-primary)' }">
                                {{ formatCurrency(total) }}
                            </span>
                        </div>
                    </div>

                    <Button label="Hacer pedido" icon="pi pi-check" :loading="submitting" @click="placeOrder"
                        class="w-full !rounded-xl !py-3"
                        :pt="{ root: { style: `background: var(--store-primary); border-color: var(--store-primary);` } }" />
                </div>
            </div>
        </div>
    </StoreLayout>
</template>
