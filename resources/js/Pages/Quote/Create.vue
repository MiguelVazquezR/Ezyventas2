<script setup>
import { ref, computed, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import CreateCustomerModal from '@/Components/CreateCustomerModal.vue';
import SelectVariantModal from '@/Components/SelectVariantModal.vue';

const props = defineProps({
    customers: Array,
    products: Array,
    services: Array,
    customFieldDefinitions: Array,
});

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Cotizaciones', url: route('quotes.index') },
    { label: 'Crear Cotización' }
]);

const form = useForm({
    customer_id: null,
    expiry_date: null,
    notes: '',
    subtotal: 0,
    total_discount: 0,
    total_tax: 0,
    shipping_cost: 0,
    total_amount: 0,
    items: [],
    custom_fields: {},
    recipient_name: '',
    recipient_email: '',
    recipient_phone: '',
    shipping_address: '',
    tax_type: null,
    tax_rate: null,
});

props.customFieldDefinitions.forEach(field => {
    form.custom_fields[field.key] = null;
});

// --- Lógica para Variantes ---
const showVariantModal = ref(false);
const productForVariantSelection = ref(null);
const itemIndexForVariantSelection = ref(null);

const addItem = () => {
    if (!selectedItem.value) return;
    const product = selectedItem.value;
    const newItem = {
        itemable_id: product.id,
        itemable_type: product.itemable_type,
        description: product.name,
        quantity: 1,
        unit_price: product.price,
        line_total: product.price,
        variant_details: null,
    };
    form.items.push(newItem);
    if (product.product_attributes && product.product_attributes.length > 0) {
        productForVariantSelection.value = product;
        itemIndexForVariantSelection.value = form.items.length - 1;
        showVariantModal.value = true;
    }
    selectedItem.value = null;
};

const openVariantSelector = (index) => {
    const item = form.items[index];
    productForVariantSelection.value = props.products.find(p => p.id === item.itemable_id);
    itemIndexForVariantSelection.value = index;
    showVariantModal.value = true;
};

const handleVariantSelected = (variant) => {
    if (itemIndexForVariantSelection.value === null) return;
    const item = form.items[itemIndexForVariantSelection.value];
    const product = productForVariantSelection.value;
    item.variant_details = variant.attributes;
    item.description = `${product.name} (${Object.values(variant.attributes).join(', ')})`;
    item.unit_price = parseFloat(product.selling_price) + parseFloat(variant.selling_price_modifier);
};

// --- Lógica para Items de la Cotización ---
const availableItems = computed(() => [
    ...props.products.map(p => ({ ...p, type: 'Producto', price: p.selling_price, itemable_type: 'App\\Models\\Product' })),
    ...props.services.map(s => ({ ...s, type: 'Servicio', price: s.base_price, itemable_type: 'App\\Models\\Service' }))
]);
const selectedItem = ref(null);
const removeItem = (index) => form.items.splice(index, 1);

// --- Lógica de Impuestos y Totales (CORREGIDA) ---
const includeTax = ref(false);
const taxType = ref('added');
const taxOptions = [{ label: 'Precio + IVA', value: 'added' }, { label: 'Precio con IVA incluido', value: 'included' }];
const taxRate = 0.16;

watch([() => form.items, () => form.total_discount, () => form.shipping_cost, includeTax, taxType], () => {
    let grossSubtotal = 0;
    form.items.forEach(item => {
        item.line_total = (parseFloat(item.quantity) || 0) * (parseFloat(item.unit_price) || 0);
        grossSubtotal += item.line_total;
    });

    const discount = parseFloat(form.total_discount) || 0;
    const shipping = parseFloat(form.shipping_cost) || 0;
    let baseSubtotal = grossSubtotal;
    let finalTax = 0;

    if (includeTax.value) {
        form.tax_rate = taxRate * 100;
        form.tax_type = taxType.value;
        const subtotalAfterDiscount = grossSubtotal - discount;

        if (taxType.value === 'added') {
            baseSubtotal = grossSubtotal;
            finalTax = subtotalAfterDiscount * taxRate;
        } else { // included
            baseSubtotal = subtotalAfterDiscount / (1 + taxRate);
            finalTax = subtotalAfterDiscount - baseSubtotal;
        }
    } else {
        form.tax_type = null;
        form.tax_rate = null;
    }
    
    form.subtotal = baseSubtotal;
    form.total_tax = finalTax;
    form.total_amount = baseSubtotal - discount + finalTax + shipping;

}, { deep: true, immediate: true });

// --- Lógica para Cliente ---
const localCustomers = ref([...props.customers]);
const showCustomerModal = ref(false);
const handleNewCustomer = (newCustomer) => {
    localCustomers.value.push(newCustomer);
    form.customer_id = newCustomer.id;
};

watch(() => form.customer_id, (newCustomerId) => {
    if (newCustomerId) {
        const selected = localCustomers.value.find(c => c.id === newCustomerId);
        if (selected) {
            form.recipient_name = selected.name;
            form.recipient_email = selected.email;
            form.recipient_phone = selected.phone;
            form.shipping_address = selected.address ? Object.values(selected.address).join(', ') : '';
        }
    }
});

const submit = () => {
    form.post(route('quotes.store'));
};

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
</script>

<template>
    <Head title="Crear Cotización" />
    <AppLayout>
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        <div class="mt-4">
            <h1 class="text-2xl font-bold">Crear Nueva Cotización</h1>
        </div>
        <form @submit.prevent="submit" class="mt-6 max-w-4xl mx-auto space-y-6">
            <!-- Información del Cliente -->
            <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                <h2 class="text-lg font-semibold border-b pb-3 mb-4">Información General</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <div class="flex justify-between items-center mb-1">
                            <InputLabel for="customer" value="Cliente *" />
                            <Button @click="showCustomerModal = true" label="Nuevo" icon="pi pi-plus" text size="small" />
                        </div>
                        <Select id="customer" v-model="form.customer_id" :options="localCustomers" filter optionLabel="name" optionValue="id" placeholder="Selecciona un cliente" class="w-full" />
                        <InputError :message="form.errors.customer_id" class="mt-2" />
                    </div>
                    <div>
                        <InputLabel for="expiry_date" value="Fecha de Expiración" />
                        <DatePicker id="expiry_date" v-model="form.expiry_date" class="w-full mt-1" />
                    </div>
                </div>
            </div>
            <!-- Items de la Cotización -->
            <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                <h2 class="text-lg font-semibold border-b pb-3 mb-4">Conceptos</h2>
                <div class="flex gap-2 mb-4">
                    <Select v-model="selectedItem" :options="availableItems" optionLabel="name" placeholder="Busca un producto o servicio..." filter class="w-full">
                        <template #option="slotProps">
                            <div>{{ slotProps.option.name }} <Tag :value="slotProps.option.type" /></div>
                        </template>
                    </Select>
                    <Button @click="addItem" icon="pi pi-plus" label="Agregar" :disabled="!selectedItem" />
                </div>
                <DataTable :value="form.items" class="p-datatable-sm">
                    <Column field="description" header="Descripción">
                        <template #body="{ data, index }">
                            <InputText v-model="form.items[index].description" class="w-full" />
                            <div v-if="data.variant_details" class="text-xs text-gray-500 mt-1">
                                <Button @click="openVariantSelector(index)" label="Cambiar variante" text size="small" class="!p-0" />
                            </div>
                        </template>
                    </Column>
                    <Column field="quantity" header="Cantidad" style="width: 10rem"><template #body="{ index }"><InputNumber v-model="form.items[index].quantity" class="w-full" showButtons buttonLayout="horizontal" :step="1" decrementButtonClass="p-button-secondary" incrementButtonClass="p-button-secondary" incrementButtonIcon="pi pi-plus" decrementButtonIcon="pi pi-minus" /></template></Column>
                    <Column field="unit_price" header="Precio Unit." style="width: 12rem"><template #body="{ index }"><InputNumber v-model="form.items[index].unit_price" mode="currency" currency="MXN" locale="es-MX" class="w-full" /></template></Column>
                    <Column field="line_total" header="Total"><template #body="{ data }">{{ formatCurrency(data.line_total) }}</template></Column>
                    <Column style="width: 4rem"><template #body="{ index }"><Button @click="removeItem(index)" icon="pi pi-trash" text rounded severity="danger" /></template></Column>
                </DataTable>
                <InputError :message="form.errors.items" class="mt-2" />
                <!-- Totales y Impuestos -->
                <div class="mt-4 space-y-4">
                    <div class="flex items-center gap-4">
                        <ToggleSwitch v-model="includeTax" inputId="include_tax" />
                        <InputLabel for="include_tax" value="Desglosar Impuestos (IVA 16%)" />
                    </div>
                    <div v-if="includeTax" class="p-3 bg-gray-50 dark:bg-gray-900 rounded-md">
                        <SelectButton v-model="taxType" :options="taxOptions" optionLabel="label" optionValue="value" />
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="md:col-start-3"><InputLabel for="subtotal" value="Subtotal" /><InputNumber id="subtotal" :modelValue="form.subtotal" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" readonly /></div>
                        <div><InputLabel for="total_discount" value="Descuento" /><InputNumber id="total_discount" v-model="form.total_discount" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" /></div>
                        <div class="md:col-start-3"><InputLabel for="total_tax" value="Impuestos" /><InputNumber id="total_tax" :modelValue="form.total_tax" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" readonly /></div>
                        <div><InputLabel for="shipping_cost" value="Costo de Envío" /><InputNumber id="shipping_cost" v-model="form.shipping_cost" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" /></div>
                        <div class="md:col-start-3 col-span-2 border-t pt-2 mt-2">
                            <InputLabel for="total_amount" value="Total" class="font-bold text-lg" />
                            <InputNumber id="total_amount" :modelValue="form.total_amount" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1 font-bold text-lg" inputClass="!font-bold !text-lg" readonly />
                        </div>
                    </div>
                </div>
            </div>
            <!-- Información de Envío y Notas -->
            <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                <h2 class="text-lg font-semibold border-b pb-3 mb-4">Información de Envío y Notas</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div><InputLabel for="recipient_name" value="Nombre de quien recibe" /><InputText id="recipient_name" v-model="form.recipient_name" class="mt-1 w-full" /></div>
                    <div><InputLabel for="recipient_phone" value="Teléfono de quien recibe" /><InputText id="recipient_phone" v-model="form.recipient_phone" class="mt-1 w-full" /></div>
                    <div class="md:col-span-2"><InputLabel for="recipient_email" value="Email de quien recibe" /><InputText id="recipient_email" v-model="form.recipient_email" type="email" class="mt-1 w-full" /></div>
                    <div class="md:col-span-2"><InputLabel for="shipping_address" value="Dirección de Envío" /><Textarea id="shipping_address" v-model="form.shipping_address" rows="3" class="mt-1 w-full" /></div>
                    <div class="md:col-span-2"><InputLabel for="notes" value="Notas Adicionales" /><Textarea id="notes" v-model="form.notes" rows="3" class="mt-1 w-full" /></div>
                </div>
            </div>
            <!-- Campos Personalizados -->
            <div v-if="customFieldDefinitions.length > 0" class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                <h2 class="text-lg font-semibold border-b pb-3 mb-4">Detalles Adicionales</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div v-for="field in customFieldDefinitions" :key="field.id">
                        <InputLabel :for="field.key" :value="field.name" />
                        <InputText v-if="field.type === 'text'" :id="field.key" v-model="form.custom_fields[field.key]" class="mt-1 w-full" />
                        <InputNumber v-if="field.type === 'number'" :id="field.key" v-model="form.custom_fields[field.key]" class="w-full mt-1" />
                        <Textarea v-if="field.type === 'textarea'" :id="field.key" v-model="form.custom_fields[field.key]" rows="2" class="mt-1 w-full" />
                        <ToggleSwitch v-if="field.type === 'boolean'" :id="field.key" v-model="form.custom_fields[field.key]" class="mt-1" />
                        <Select v-if="field.type === 'select'" :id="field.key" v-model="form.custom_fields[field.key]" :options="field.options" class="mt-1 w-full" placeholder="Selecciona una opción" />
                        <InputError :message="form.errors[`custom_fields.${field.key}`]" class="mt-2" />
                    </div>
                </div>
            </div>
            <div class="flex justify-end">
                <Button type="submit" label="Crear Cotización" :loading="form.processing" severity="warning" />
            </div>
        </form>
        <CreateCustomerModal v-model:visible="showCustomerModal" @created="handleNewCustomer" />
        <SelectVariantModal v-model:visible="showVariantModal" :product="productForVariantSelection" @variant-selected="handleVariantSelected" />
    </AppLayout>
</template>