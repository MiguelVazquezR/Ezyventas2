<script setup>
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import CreateCustomerModal from '@/Components/CreateCustomerModal.vue';
import SelectVariantModal from '@/Components/SelectVariantModal.vue';
import ManageCustomFields from '@/Components/ManageCustomFields.vue';
import PatternLock from '@/Components/PatternLock.vue';
import { usePermissions } from '@/Composables';
import { useConfirm } from "primevue/useconfirm";

const props = defineProps({
    customers: Array,
    products: Array,
    services: Array,
    customFieldDefinitions: Array,
});

// --- INICIO: Lógica añadida ---
const confirm = useConfirm();
const { hasPermission } = usePermissions();
const manageFieldsComponent = ref(null);

const openCustomFieldManager = () => {
    if (manageFieldsComponent.value) {
        manageFieldsComponent.value.open();
    }
};
// --- FIN: Lógica añadida ---

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

// --- Lógica de inicialización de campos personalizada ---
const initializeCustomFields = (definitions) => {
    const newCustomFields = {};
    definitions.forEach(field => {
        if (form.custom_fields.hasOwnProperty(field.key)) {
            newCustomFields[field.key] = form.custom_fields[field.key];
        } else {
            newCustomFields[field.key] = field.type === 'checkbox' ? [] : (field.type === 'boolean' ? false : (field.type === 'pattern' ? [] : null));
        }
    });
    form.custom_fields = newCustomFields;
};
initializeCustomFields(props.customFieldDefinitions);
watch(() => props.customFieldDefinitions, (newDefs) => {
    initializeCustomFields(newDefs);
}, { deep: true });
// --- FIN: Lógica de inicialización ---

// --- Lógica para Variantes ---
const showVariantModal = ref(false);
const productForVariantSelection = ref(null);
const itemIndexForVariantSelection = ref(null);

const openVariantSelector = (index) => {
    const item = form.items[index];
    let product = null;

    // 1. Si es un Producto base, buscarlo directamente
    if (item.itemable_type === 'App\\Models\\Product') {
        product = props.products.find(p => p.id === item.itemable_id);
    } 
    // 2. Si ya es una Variante (ProductAttribute), buscar el producto padre que contiene esta variante
    else if (item.itemable_type === 'App\\Models\\ProductAttribute') {
        product = props.products.find(p => p.product_attributes?.some(attr => attr.id === item.itemable_id));
    }

    if (product) {
        productForVariantSelection.value = product;
        itemIndexForVariantSelection.value = index;
        showVariantModal.value = true;
    } else {
        console.warn("No se pudo encontrar el producto para seleccionar variantes.");
    }
};

const handleVariantSelected = (variant) => {
    if (itemIndexForVariantSelection.value === null || !form.items[itemIndexForVariantSelection.value]) return;
    
    const item = form.items[itemIndexForVariantSelection.value];
    const product = productForVariantSelection.value;
    
    // El itemable ahora es la VARIANTE
    item.itemable_id = variant.id;
    item.itemable_type = 'App\\Models\\ProductAttribute';

    item.variant_details = variant.attributes;
    item.description = `${product.name} (${Object.values(variant.attributes).join(', ')})`;
    item.unit_price = (parseFloat(product.selling_price) || 0) + (parseFloat(variant.selling_price_modifier) || 0);
};


// --- Lógica para Items de la Cotización ---
const itemTypeOptions = ref([
    { label: 'Producto', value: 'App\\Models\\Product' },
    { label: 'Servicio', value: 'App\\Models\\Service' },
]);

const availableItems = computed(() => [
    ...props.products.map(p => ({ ...p, type: 'Producto', price: p.selling_price, itemable_type: 'App\\Models\\Product' })),
    ...props.services.map(s => ({ ...s, type: 'Servicio', price: s.base_price, itemable_type: 'App\\Models\\Service' }))
]);
const selectedItem = ref(null);
const filteredItems = ref([]);

const searchItems = (event) => {
    if (!event.query.trim().length) {
        filteredItems.value = [...availableItems.value];
    } else {
        filteredItems.value = availableItems.value.filter((item) => item.name.toLowerCase().includes(event.query.toLowerCase()));
    }
};

// Helper para saber si mostrar el botón de variante
const canSelectVariant = (item) => {
    if (!item.itemable_id) return false;
    
    // Si ya es un atributo, por definición tiene variantes (es una de ellas)
    if (item.itemable_type === 'App\\Models\\ProductAttribute') return true;
    
    // Si es un producto, verificar si tiene atributos configurados
    if (item.itemable_type === 'App\\Models\\Product') {
         const p = props.products.find(p => p.id === item.itemable_id);
         return p && p.product_attributes && p.product_attributes.length > 0;
    }
    return false;
};

const addItem = () => {
    let itemToAdd = {
        itemable_id: null,
        itemable_type: 'App\\Models\\Service',
        description: '',
        quantity: 1,
        unit_price: 0,
        line_total: 0,
        variant_details: null,
    };

    let triggerVariantModal = false;
    let productForModal = null;
    if (typeof selectedItem.value === 'object' && selectedItem.value !== null) {
        const selected = selectedItem.value;
        itemToAdd = {
            ...itemToAdd,
            itemable_id: selected.id,
            itemable_type: selected.itemable_type,
            description: selected.name,
            unit_price: selected.price
        };

        if (selected.itemable_type === 'App\\Models\\Product' && selected.product_attributes && selected.product_attributes.length > 0) {
            triggerVariantModal = true;
            productForModal = selected;
        }

    } else if (typeof selectedItem.value === 'string' && selectedItem.value.trim() !== '') {
        itemToAdd = {
            ...itemToAdd,
            itemable_id: 0,
            description: selectedItem.value
        };
    } else {
        return;
    }

    form.items.push(itemToAdd);

    if (triggerVariantModal) {
        productForVariantSelection.value = productForModal;
        itemIndexForVariantSelection.value = form.items.length - 1;
        showVariantModal.value = true;
    }

    selectedItem.value = null;
};


const removeItem = (index) => form.items.splice(index, 1);

const confirmRemoveItem = (event, index) => {
    confirm.require({
        target: event.currentTarget,
        message: '¿Estás seguro de que quieres eliminar este elemento?',
        group: 'concept-delete',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Sí',
        rejectLabel: 'No',
        accept: () => {
            removeItem(index);
        }
    });
};

const checkUnitPrice = (index) => {
    setTimeout(() => {
        if (form.items[index].unit_price === null || form.items[index].unit_price === undefined) {
            form.items[index].unit_price = 0;
        }
    }, 0);
};

// --- Lógica de Impuestos y Totales ---
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

    form.subtotal = (taxType.value === 'included' && includeTax.value) ? baseSubtotal : grossSubtotal;
    form.total_tax = finalTax;

    let total = 0;
    if (includeTax.value && taxType.value === 'included') {
        total = (baseSubtotal + finalTax) + shipping;
    } else {
        total = (baseSubtotal - discount) + finalTax + shipping;
    }
    form.total_amount = total;

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
    } else {
        form.recipient_name = '';
        form.recipient_email = '';
        form.recipient_phone = '';
        form.shipping_address = '';
    }
});

const submit = () => {
    form.post(route('quotes.store'));
};

const formatCurrency = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
</script>

<template>
    <AppLayout title="Crear cotización">
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        <div class="mt-4">
            <h1 class="text-2xl font-bold">Crear nueva cotización</h1>
        </div>
        <form @submit.prevent="submit" class="mt-6 max-w-4xl mx-auto space-y-6">

            <!-- Información general -->
            <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                <h2 class="text-lg font-semibold border-b pb-3 mb-4">Información general</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <div class="flex justify-between items-center mb-1">
                            <InputLabel for="customer" value="Cliente (Opcional)" />
                            <Button @click="showCustomerModal = true" label="Nuevo" icon="pi pi-plus" text
                                size="small" />
                        </div>
                        <Select size="large" id="customer" v-model="form.customer_id" :options="localCustomers" filter
                            optionLabel="name" optionValue="id" placeholder="Selecciona un cliente" class="w-full"
                            showClear />
                        <InputError :message="form.errors.customer_id" />
                    </div>
                    <div class="mt-3">
                        <InputLabel for="expiry_date" value="Fecha de expiración" />
                        <DatePicker id="expiry_date" v-model="form.expiry_date" class="w-full mt-1"
                            dateFormat="dd/mm/yy" />
                    </div>

                    <div>
                        <InputLabel for="recipient_name" value="Nombre de quien recibe *" />
                        <InputText id="recipient_name" v-model="form.recipient_name" class="mt-1 w-full" />
                        <InputError :message="form.errors.recipient_name" class="mt-2" />
                    </div>
                    <div>
                        <InputLabel for="recipient_phone" value="Teléfono de quien recibe" />
                        <InputText id="recipient_phone" v-model="form.recipient_phone" class="mt-1 w-full" />
                        <InputError :message="form.errors.recipient_phone" class="mt-2" />
                    </div>
                    <div class="md:col-span-2">
                        <InputLabel for="recipient_email" value="Email de quien recibe" />
                        <InputText id="recipient_email" v-model="form.recipient_email" type="email"
                            class="mt-1 w-full" />
                        <InputError :message="form.errors.recipient_email" class="mt-2" />
                    </div>
                </div>
            </div>

            <!-- Items de la Cotización -->
            <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                <h2 class="text-lg font-semibold border-b pb-3 mb-4">Conceptos</h2>
                <div class="flex gap-2 mb-4">
                    <AutoComplete v-model="selectedItem" :suggestions="filteredItems" @complete="searchItems"
                        field="name" optionLabel="name" placeholder="Busca o escribe un concepto..." class="w-full"
                        dropdown>
                        <template #option="slotProps">
                            <div>{{ slotProps.option.name }}
                                <Tag :value="slotProps.option.type" />
                            </div>
                        </template>
                    </AutoComplete>
                    <Button @click="addItem" icon="pi pi-plus" label="Agregar" :disabled="!selectedItem" />
                </div>

                <DataTable :value="form.items" class="p-datatable-sm">
                    <template #empty>
                        <div class="text-center p-4">No se han agregado conceptos.</div>
                    </template>
                    <Column header="Tipo" style="width: 15rem">
                        <template #body="{ data, index }">
                            <!-- Lógica corregida: Si es ProductAttribute, visualmente sigue siendo 'Producto' -->
                            <SelectButton 
                                :model-value="['App\\Models\\Product', 'App\\Models\\ProductAttribute'].includes(form.items[index].itemable_type) ? 'App\\Models\\Product' : form.items[index].itemable_type"
                                @update:model-value="(val) => form.items[index].itemable_type = val"
                                :options="itemTypeOptions"
                                optionLabel="label" 
                                optionValue="value" 
                                :allowEmpty="false"
                                :disabled="data.itemable_id !== 0 && data.itemable_id !== null" 
                                class="w-full" 
                            />
                        </template>
                    </Column>
                    <Column field="description" header="Descripción">
                        <template #body="{ data, index }">
                            <InputText v-model="form.items[index].description" fluid class="w-full" />
                             <!-- Botón para cambiar variante (lógica corregida) -->
                            <div v-if="canSelectVariant(data)" class="text-xs text-gray-500 mt-1">
                                <Button 
                                    @click="openVariantSelector(index)" 
                                    :label="data.variant_details ? 'Cambiar variante' : 'Seleccionar variante'" 
                                    text size="small" class="!p-0" 
                                />
                            </div>
                        </template>
                    </Column>
                    <Column field="quantity" header="Cantidad" style="width: 9.5rem">
                        <template #body="{ index }">
                            <InputNumber v-model="form.items[index].quantity" fluid class="w-full" showButtons
                                buttonLayout="horizontal" :step="1" :min="1" />
                        </template>
                    </Column>
                    <Column field="unit_price" header="Precio Unit." style="width: 9.5rem">
                        <template #body="{ index }">
                            <InputNumber v-model="form.items[index].unit_price" @blur="checkUnitPrice(index)"
                                mode="currency" currency="MXN" locale="es-MX" fluid class="w-full" />
                        </template>
                    </Column>
                    <Column field="line_total" header="Total">
                        <template #body="{ data }">{{ formatCurrency(data.line_total) }}</template>
                    </Column>
                    <Column style="width: 4rem">
                        <template #body="{ index }">
                            <Button @click="confirmRemoveItem($event, index)" icon="pi pi-trash" text rounded
                                size="small" severity="danger" />
                        </template>
                    </Column>
                </DataTable>
                <InputError :message="form.errors.items" class="mt-2" />

                <!-- Totales y Impuestos -->
                <div class="mt-4 space-y-4">
                    <div class="flex items-center gap-4">
                        <ToggleSwitch v-model="includeTax" inputId="include_tax" />
                        <InputLabel for="include_tax" value="Desglosar impuestos (IVA 16%)" />
                    </div>
                    <div v-if="includeTax" class="p-3 bg-gray-50 dark:bg-gray-900 rounded-md">
                        <SelectButton v-model="taxType" :options="taxOptions" optionLabel="label" optionValue="value" />
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="md:col-start-3">
                            <InputLabel for="subtotal" value="Subtotal" />
                            <InputNumber id="subtotal" :modelValue="form.subtotal" mode="currency" currency="MXN"
                                locale="es-MX" class="w-full mt-1" readonly />
                        </div>
                        <div>
                            <InputLabel for="total_discount" value="Descuento" />
                            <InputNumber id="total_discount" v-model="form.total_discount" mode="currency"
                                currency="MXN" locale="es-MX" class="w-full mt-1" />
                        </div>
                        <div class="md:col-start-3">
                            <InputLabel for="total_tax" value="Impuestos" />
                            <InputNumber id="total_tax" :modelValue="form.total_tax" mode="currency" currency="MXN"
                                locale="es-MX" class="w-full mt-1" readonly />
                        </div>
                        <div>
                            <InputLabel for="shipping_cost" value="Costo de envío / visita" />
                            <InputNumber id="shipping_cost" v-model="form.shipping_cost" mode="currency" currency="MXN"
                                locale="es-MX" class="w-full mt-1" />
                        </div>
                        <div class="md:col-start-3 col-span-2 border-t pt-2 mt-2">
                            <InputLabel for="total_amount" value="Total" class="font-bold text-lg" />
                            <InputNumber id="total_amount" :modelValue="form.total_amount" mode="currency"
                                currency="MXN" locale="es-MX" class="w-full mt-1 font-bold text-lg"
                                inputClass="!font-bold !text-lg" readonly />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Información de Envío y Notas -->
            <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                <h2 class="text-lg font-semibold border-b pb-3 mb-4">Información de envío y notas</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="md:col-span-2">
                        <InputLabel for="shipping_address" value="Dirección de envío" />
                        <Textarea id="shipping_address" v-model="form.shipping_address" rows="3" class="mt-1 w-full" />
                    </div>
                    <div class="md:col-span-2">
                        <InputLabel for="notes" value="Notas adicionales" />
                        <Textarea id="notes" v-model="form.notes" rows="3" class="mt-1 w-full" />
                    </div>
                </div>
            </div>

            <!-- Bloque de Campos Personalizados -->
            <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                <div class="flex items-center justify-between border-b pb-3 mb-4">
                    <h2 class="text-lg font-semibold">Detalles adicionales</h2>
                    <Button v-if="hasPermission('quotes.manage_custom_fields')" @click="openCustomFieldManager"
                        icon="pi pi-cog" text label="Gestionar" v-tooltip.left="'Gestionar campos personalizados'" />
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div v-for="field in customFieldDefinitions" :key="field.id">
                        <InputLabel :for="field.key" :value="field.name" />
                        <InputText v-if="field.type === 'text'" :id="field.key" v-model="form.custom_fields[field.key]"
                            class="mt-1 w-full" />
                        <InputNumber v-if="field.type === 'number'" :id="field.key"
                            v-model="form.custom_fields[field.key]" class="w-full mt-1" />
                        <Textarea v-if="field.type === 'textarea'" :id="field.key"
                            v-model="form.custom_fields[field.key]" rows="2" class="mt-1 w-full" />
                        <ToggleSwitch v-if="field.type === 'boolean'" :id="field.key"
                            v-model="form.custom_fields[field.key]" class="mt-1" />
                        <PatternLock v-if="field.type === 'pattern'" :id="field.key"
                            v-model="form.custom_fields[field.key]" class="mt-1" />
                        <Dropdown v-if="field.type === 'select'" :id="field.key" v-model="form.custom_fields[field.key]"
                            :options="field.options" class="mt-1 w-full" placeholder="Selecciona una opción" />
                        <div v-if="field.type === 'checkbox'" class="flex flex-col gap-2 mt-2">
                            <div v-for="option in field.options" :key="option" class="flex items-center">
                                <Checkbox :inputId="`${field.key}-${option}`" v-model="form.custom_fields[field.key]"
                                    :value="option" />
                                <label :for="`${field.key}-${option}`" class="ml-2"> {{ option }} </label>
                            </div>
                        </div>
                        <InputError :message="form.errors[`custom_fields.${field.key}`]" class="mt-2" />
                    </div>
                    <p v-if="!customFieldDefinitions.length && hasPermission('quotes.manage_custom_fields')"
                        class="col-span-full text-center text-gray-500">
                        Actualmente no tienes ningún campo adicional, pero puedes agregar los que requieras
                        haciendo clic en el ícono de engranaje (<i class="pi pi-cog"></i> Gestionar) en la parte
                        superior derecha.
                    </p>
                    <p v-if="!hasPermission('quotes.manage_custom_fields')"
                        class="col-span-full text-center text-gray-500">
                        Actualmente no tienes ningún campo adicional, pero puedes solicitar a un administrador que agregue
                        los que requieras.
                    </p>
                </div>
            </div>

            <div class="flex justify-end sticky bottom-4">
                <Button type="submit" label="Crear cotización" :loading="form.processing" severity="warning" />
            </div>
        </form>

        <CreateCustomerModal v-model:visible="showCustomerModal" @created="handleNewCustomer" />
        <SelectVariantModal v-model:visible="showVariantModal" :product="productForVariantSelection"
            @variant-selected="handleVariantSelected" />

        <ManageCustomFields ref="manageFieldsComponent" module="quotes" :definitions="props.customFieldDefinitions" />

        <ConfirmPopup group="concept-delete" />

    </AppLayout>
</template>