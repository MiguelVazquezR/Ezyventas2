<script setup>
import { ref, computed, onMounted } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import StoreLayout from '@/Layouts/StoreLayout.vue';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import InputMask from 'primevue/inputmask';
import Textarea from 'primevue/textarea';
import SelectButton from 'primevue/selectbutton';
import InputNumber from 'primevue/inputnumber';
import Message from 'primevue/message';
import { useToast } from 'primevue/usetoast';

const page = usePage();
const store = computed(() => page.props.store || {});
const toast = useToast();

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

const subtotal = computed(() => cartItems.value.reduce((sum, i) => sum + i.price * i.quantity, 0));

const deliveryTypes = computed(() => {
    const types = [];
    if (store.value.accepts_pickup) types.push({ label: 'Recoger en tienda', value: 'pickup' });
    if (store.value.accepts_delivery) types.push({ label: 'Envío a domicilio', value: 'delivery' });
    return types;
});

const deliveryType = ref('pickup');
const deliveryFee = computed(() => deliveryType.value === 'delivery' ? (store.value.delivery_fee || 0) : 0);
const total = computed(() => subtotal.value + deliveryFee.value);

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

    router.post(route('store.order.place', { slug: new URL(window.location.href).pathname.split('/')[2] }), {
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
    root: { class: '!rounded-xl !bg-white !border-gray-200 focus:!border-primary-500 transition-colors' }
};

const isEmpty = computed(() => cartItems.value.length === 0);
</script>

<template>
    <Head title="Carrito" />
    <StoreLayout>
        <div class="max-w-4xl mx-auto px-4 py-8">
            <h1 class="text-2xl font-bold text-gray-900 mb-8 m-0">Tu pedido</h1>

            <!-- Empty cart -->
            <div v-if="isEmpty" class="bg-white rounded-2xl border border-gray-200 p-12 text-center">
                <i class="pi pi-shopping-cart !text-5xl text-gray-300 mb-4 block" />
                <p class="text-gray-500 text-lg mb-4">Tu carrito está vacío.</p>
                <a :href="route('store.home', { slug: $page.url.split('/')[2] })" class="text-sm font-semibold" style="color: var(--store-primary)">Ver productos</a>
            </div>

            <div v-else class="grid grid-cols-1 lg:grid-cols-5 gap-6">
                <!-- Cart items -->
                <div class="lg:col-span-3 space-y-4">
                    <div v-for="(item, index) in cartItems" :key="index" class="bg-white rounded-2xl border border-gray-200 p-4 flex gap-4">
                        <div class="w-16 h-16 bg-gray-50 rounded-xl flex items-center justify-center shrink-0">
                            <img v-if="item.image_url" :src="item.image_url" class="max-h-full max-w-full object-contain" />
                            <i v-else class="pi pi-image text-gray-300" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="font-medium text-sm text-gray-900 m-0 truncate">{{ item.name }}</h3>
                            <p class="text-sm font-bold mt-1 m-0" style="color: var(--store-primary)">{{ formatCurrency(item.price) }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <InputNumber v-model="item.quantity" :min="1" :max="99" class="w-16" @update:modelValue="updateQuantity(index, $event)" :pt="{ input: { root: { class: '!rounded-xl !text-center !text-sm' } } }" />
                            <Button icon="pi pi-trash" text rounded severity="danger" size="small" @click="removeItem(index)" />
                        </div>
                    </div>
                </div>

                <!-- Order form -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Delivery type -->
                    <div class="bg-white rounded-2xl border border-gray-200 p-4">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-3 block">Tipo de entrega</label>
                        <SelectButton v-model="deliveryType" :options="deliveryTypes" optionLabel="label" optionValue="value" class="w-full" :pt="{ button: { class: '!text-xs !rounded-xl flex-1' } }" />
                    </div>

                    <!-- Customer info -->
                    <div class="bg-white rounded-2xl border border-gray-200 p-4 space-y-3">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 block">Tu información</label>
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
                    <div class="bg-white rounded-2xl border border-gray-200 p-4 space-y-2">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500">Subtotal</span>
                            <span class="font-mono font-semibold">{{ formatCurrency(subtotal) }}</span>
                        </div>
                        <div v-if="deliveryFee > 0" class="flex justify-between text-sm">
                            <span class="text-gray-500">Costo de envío</span>
                            <span class="font-mono">{{ formatCurrency(deliveryFee) }}</span>
                        </div>
                        <div class="flex justify-between font-bold text-lg pt-2 border-t border-gray-100">
                            <span>Total</span>
                            <span class="font-mono" style="color: var(--store-primary)">{{ formatCurrency(total) }}</span>
                        </div>
                    </div>

                    <Button label="Hacer pedido" icon="pi pi-check" :loading="submitting" @click="placeOrder" class="w-full !rounded-xl !py-3" style="background: var(--store-primary); border-color: var(--store-primary)" />
                </div>
            </div>
        </div>
    </StoreLayout>
</template>
