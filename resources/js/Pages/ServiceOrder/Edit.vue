<script setup>
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import PatternLock from '@/Components/PatternLock.vue';
import ManageCustomFields from '@/Components/ManageCustomFields.vue';
// --- INICIO: Imports añadidos ---
import SelectVariantModal from '@/Components/SelectVariantModal.vue';
// --- FIN: Imports añadidos ---
import { useConfirm } from "primevue/useconfirm";
import { usePermissions } from '@/Composables';

const props = defineProps({
    serviceOrder: Object,
    customFieldDefinitions: Array,
    customers: Array,
    products: Array,
    services: Array,
    errors: Object,
});

const confirm = useConfirm();
const { hasPermission } = usePermissions();

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Ódenes de Servicio', url: route('service-orders.index') },
    { label: `Editar Orden #${props.serviceOrder.folio || props.serviceOrder.id}` }
]);

const form = useForm({
    _method: 'PUT',
    customer_id: props.serviceOrder.customer_id,
    customer_name: props.serviceOrder.customer_name,
    customer_phone: props.serviceOrder.customer_phone,
    customer_email: props.serviceOrder.customer_email,
    item_description: props.serviceOrder.item_description,
    reported_problems: props.serviceOrder.reported_problems,
    promised_at: props.serviceOrder.promised_at ? new Date(props.serviceOrder.promised_at) : null,
    items: props.serviceOrder.items || [],
    subtotal: props.serviceOrder.subtotal,
    discount_type: props.serviceOrder.discount_type || 'fixed',
    discount_value: props.serviceOrder.discount_value || 0,
    discount_amount: props.serviceOrder.discount_amount || 0,
    final_total: props.serviceOrder.final_total,
    custom_fields: props.serviceOrder.custom_fields || {},
    initial_evidence_images: [],
    deleted_media_ids: [],
    assign_technician: !!props.serviceOrder.technician_name,
    technician_name: props.serviceOrder.technician_name,
    technician_commission_type: props.serviceOrder.technician_commission_type || 'percentage',
    technician_commission_value: props.serviceOrder.technician_commission_value,
});

// --- LÓGICA MEJORADA PARA CÁLCULOS Y SUBTOTAL MANUAL ---
const manualSubtotalMode = ref(false);

const recalculateSubtotal = () => {
    let subtotal = 0;
    form.items.forEach(item => {
        const lineTotal = (item.quantity || 0) * (item.unit_price || 0);
        item.line_total = lineTotal;
        subtotal += lineTotal;
    });
    form.subtotal = subtotal;
};

const toggleSubtotalMode = () => {
    manualSubtotalMode.value = !manualSubtotalMode.value;
    if (!manualSubtotalMode.value) {
        recalculateSubtotal();
    }
};

// Watcher #1: Recalcula automáticamente el subtotal si cambian los items y no está en modo manual.
watch(() => form.items, () => {
    if (!manualSubtotalMode.value) {
        recalculateSubtotal();
    }
}, { deep: true });

// Watcher #2: Recalcula siempre el descuento y el total final cuando cambia el subtotal o el descuento.
watch([() => form.subtotal, () => form.discount_type, () => form.discount_value], ([subtotal, discountType, discountValue]) => {
    const sub = subtotal || 0;
    const val = discountValue || 0;
    let discountAmount = 0;

    if (discountType === 'percentage') {
        discountAmount = (sub * val) / 100;
    } else { // fixed
        discountAmount = val;
    }

    if (discountAmount > sub) {
        discountAmount = sub;
        if (discountType === 'fixed') form.discount_value = sub;
        else if (discountType === 'percentage') form.discount_value = 100;
    }

    form.discount_amount = discountAmount;
    form.final_total = sub - discountAmount;
}, { immediate: true });
// --- FIN DE LA LÓGICA MEJORADA ---

// --- INICIO: Lógica de Variantes (Integrada) ---
const showVariantModal = ref(false);
const productForVariantSelection = ref(null);
const itemIndexForVariantSelection = ref(null);

const openVariantSelector = (index) => {
    const item = form.items[index];
    let product = null;

    // 1. Si es un Producto base
    if (item.itemable_type === 'App\\Models\\Product') {
        product = props.products.find(p => p.id === item.itemable_id);
    } 
    // 2. Si ya es una Variante (ProductAttribute)
    else if (item.itemable_type === 'App\\Models\\ProductAttribute') {
        product = props.products.find(p => p.product_attributes?.some(attr => attr.id === item.itemable_id));
    }

    if (product) {
        productForVariantSelection.value = product;
        itemIndexForVariantSelection.value = index;
        showVariantModal.value = true;
    }
};

const handleVariantSelected = (variant) => {
    if (itemIndexForVariantSelection.value === null || !form.items[itemIndexForVariantSelection.value]) return;
    
    const item = form.items[itemIndexForVariantSelection.value];
    const product = productForVariantSelection.value;
    
    // Actualizar a ProductAttribute
    item.itemable_id = variant.id;
    item.itemable_type = 'App\\Models\\ProductAttribute';

    item.variant_details = variant.attributes;
    item.description = `${product.name} (${Object.values(variant.attributes).join(', ')})`;
    
    // Actualizar precio base + modificador
    item.unit_price = (parseFloat(product.selling_price) || 0) + (parseFloat(variant.selling_price_modifier) || 0);

    if (!manualSubtotalMode.value) recalculateSubtotal();
};

const canSelectVariant = (item) => {
    if (!item.itemable_id) return false;
    if (item.itemable_type === 'App\\Models\\ProductAttribute') return true;
    if (item.itemable_type === 'App\\Models\\Product') {
         const p = props.products.find(p => p.id === item.itemable_id);
         return p && p.product_attributes && p.product_attributes.length > 0;
    }
    return false;
};
// --- FIN: Lógica de Variantes ---

// --- Lógica de Items ---
const itemTypeOptions = ref([
    { label: 'Refacción', value: 'App\\Models\\Product' },
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

const addItem = () => {
    let itemToAdd = {
        itemable_id: null,
        itemable_type: 'App\\Models\\Service',
        description: '',
        quantity: 1,
        unit_price: 0,
        line_total: 0,
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

         // Verificar variantes
        if (selected.itemable_type === 'App\\Models\\Product' && selected.product_attributes && selected.product_attributes.length > 0) {
            triggerVariantModal = true;
            productForModal = selected;
        }

    } else if (typeof selectedItem.value === 'string') {
        itemToAdd = { ...itemToAdd, itemable_id: 0, description: selectedItem.value };
    } else { return; }

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

const filteredCustomers = ref();
const searchCustomer = (event) => {
    setTimeout(() => {
        if (!event.query.trim().length) { filteredCustomers.value = [...props.customers]; }
        else { filteredCustomers.value = props.customers.filter((customer) => customer.name.toLowerCase().startsWith(event.query.toLowerCase())); }
    }, 250);
}
const onCustomerSelect = (event) => {
    const customer = event.value;
    form.customer_id = customer.id;
    form.customer_name = customer.name;
    form.customer_phone = customer.phone;
    form.customer_email = customer.email;
    if (customer.address) { form.customer_address = customer.address; }
};

const commissionOptions = ref([{ label: 'Porcentaje (%)', value: 'percentage' }, { label: 'Monto Fijo ($)', value: 'fixed' }]);
const discountTypeOptions = ref([{ label: 'Fijo ($)', value: 'fixed' }, { label: 'Porcentaje (%)', value: 'percentage' }]);

watch(() => form.assign_technician, (newValue) => {
    if (!newValue) { form.technician_name = ''; form.technician_commission_type = 'percentage'; form.technician_commission_value = null; }
});

const initializeCustomFields = (definitions) => {
    const newCustomFields = {};
    definitions.forEach(field => {
        if (form.custom_fields && form.custom_fields.hasOwnProperty(field.key)) {
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

const manageFieldsComponent = ref(null);
const openCustomFieldManager = () => {
    if (manageFieldsComponent.value) {
        manageFieldsComponent.value.open();
    }
};

const existingMedia = ref((props.serviceOrder.media || []).filter(m => m.collection_name === 'initial-service-order-evidence'));

const removeExistingImage = (mediaId) => {
    form.deleted_media_ids.push(mediaId);
    existingMedia.value = existingMedia.value.filter(m => m.id !== mediaId);
};
const onSelectImages = (event) => form.initial_evidence_images = event.files;
const onRemoveImage = (event) => form.initial_evidence_images = form.initial_evidence_images.filter(img => img.objectURL !== event.file.objectURL);

const submit = () => {
    form.post(route('service-orders.update', props.serviceOrder.id));
};

</script>

<template>
    <AppLayout :title="`Editar orden de servicio #${serviceOrder.folio || serviceOrder.id}`">
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        <div class="mt-4">
            <h1 class="text-2xl font-bold">Editar Orden de Servicio #{{ serviceOrder.folio || serviceOrder.id }}</h1>
        </div>

        <form @submit.prevent="submit" class="mt-6 max-w-4xl mx-auto space-y-6">
            <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                <h2 class="text-lg font-semibold border-b pb-3 mb-4">Información Principal</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <InputLabel for="customer_name" value="Nombre del Cliente *" />
                        <AutoComplete v-model="form.customer_name" :suggestions="filteredCustomers"
                            @complete="searchCustomer" field="name" @item-select="onCustomerSelect" inputClass="w-full"
                            class="w-full mt-1" inputId="customer_name">
                            <template #option="slotProps">
                                <div>{{ slotProps.option.name }}</div>
                                <div class="text-xs text-gray-500">{{ slotProps.option.phone }}</div>
                            </template>
                        </AutoComplete>
                        <InputError :message="form.errors.customer_name" class="mt-2" />
                    </div>
                    <div>
                        <InputLabel for="customer_phone" value="Teléfono del Cliente" />
                        <InputText id="customer_phone" v-model="form.customer_phone" class="mt-1 w-full" />
                    </div>
                    <div>
                        <InputLabel for="customer_email" value="Correo Electrónico" />
                        <InputText id="customer_email" v-model="form.customer_email" class="mt-1 w-full" />
                        <InputError :message="form.errors.customer_email" class="mt-2" />
                    </div>
                    <div>
                        <InputLabel for="promised_at" value="Fecha Promesa de Entrega" />
                        <DatePicker id="promised_at" v-model="form.promised_at" class="w-full mt-1"
                            dateFormat="dd/mm/yy" />
                        <InputError :message="form.errors.promised_at" class="mt-2" />
                    </div>
                    <div class="md:col-span-2">
                        <InputLabel for="item_description" value="Descripción del Equipo *" />
                        <InputText id="item_description" v-model="form.item_description" class="mt-1 w-full"
                            placeholder="Ej: iPhone 13 Pro, 256GB, Azul Sierra" />
                        <InputError :message="form.errors.item_description" class="mt-2" />
                    </div>
                    <div class="md:col-span-2">
                        <InputLabel for="reported_problems" value="Falla o Problema Reportado por el Cliente *" />
                        <Textarea id="reported_problems" v-model="form.reported_problems" rows="3"
                            class="mt-1 w-full" />
                        <InputError :message="form.errors.reported_problems" class="mt-2" />
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                <h2 class="text-lg font-semibold border-b pb-3 mb-4">Refacciones y Mano de Obra</h2>
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
                        <div class="text-center p-4">No se han agregado refacciones o servicios.</div>
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
                            <div v-if="['App\\Models\\Product', 'App\\Models\\ProductAttribute'].includes(form.items[index].itemable_type) && form.items[index].itemable_id && form.items[index].itemable_id !== 0"
                                class="text-xs text-gray-500 dark:text-gray-400 italic mt-1 pl-1">
                                (Se descontarán {{ form.items[index].quantity || 0 }} unidad(es) del stock)
                            </div>
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
                        <template #body="{ data }">{{ new
                        Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(data.line_total)
                            }}</template>
                    </Column>
                    <Column style="width: 4rem">
                        <template #body="{ index, event }">
                            <Button @click="confirmRemoveItem($event, index)" icon="pi pi-trash" text rounded size="small"
                                severity="danger" />
                        </template>
                    </Column>
                </DataTable>
                <InputError :message="form.errors.items" class="mt-2" />
                
                <!-- SECCIÓN DE TOTALES Y DESCUENTOS MEJORADA -->
                <div class="flex justify-end mt-6">
                    <div class="w-full max-w-xl bg-gray-50 dark:bg-gray-700/20 p-4 rounded-lg space-y-3">
                        <!-- Subtotal -->
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-2">
                                <Button :icon="manualSubtotalMode ? 'pi pi-lock' : 'pi pi-lock-open'"
                                    @click="toggleSubtotalMode"
                                    :severity="manualSubtotalMode ? 'secondary' : 'success'" text rounded size="small"
                                    v-tooltip.left="manualSubtotalMode ? 'Cambiar a cálculo automático' : 'Cambiar a subtotal manual'" />
                                <label class="font-semibold text-gray-700 dark:text-gray-300">Subtotal</label>
                            </div>
                            <InputNumber v-model="form.subtotal" mode="currency" currency="MXN" locale="es-MX"
                                :disabled="!manualSubtotalMode" inputClass="font-semibold text-right !w-[120px]" />
                        </div>
                        <!-- Descuento -->
                        <div class="flex justify-between items-center">
                            <label class="font-semibold text-gray-700 dark:text-gray-300 pl-10">Descuento</label>
                            <div class="flex items-center gap-2">
                                <SelectButton v-model="form.discount_type" :options="discountTypeOptions"
                                    optionLabel="label" optionValue="value" />
                                <InputNumber fluid v-model="form.discount_value" class="max-w-[120px]" :min="0"
                                    :max="form.discount_type === 'percentage' ? 100 : form.subtotal"
                                    :prefix="form.discount_type === 'fixed' ? '$' : null"
                                    :suffix="form.discount_type === 'percentage' ? '%' : null" />
                            </div>
                        </div>
                        <!-- Monto Descontado (solo si hay descuento) -->
                        <div v-if="form.discount_amount > 0"
                            class="flex justify-end items-center text-sm text-red-600 dark:text-red-400 pr-1">
                            <span>- {{ new Intl.NumberFormat('es-MX', { style: 'currency', currency:
                            'MXN' }).format(form.discount_amount) }}</span>
                        </div>
                        <Divider class="!my-2" />
                        <!-- Total Final -->
                        <div class="flex justify-between items-center text-xl font-bold">
                            <span class="text-gray-800 dark:text-gray-200">TOTAL:</span>
                            <span>{{ new Intl.NumberFormat('es-MX', { style: 'currency',
                                currency: 'MXN' }).format(form.final_total) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                <div class="flex items-center justify-between border-b pb-3 mb-4">
                    <h2 class="text-lg font-semibold">Asignación de Técnico</h2>
                    <ToggleSwitch v-model="form.assign_technician" inputId="assign_technician" />
                </div>
                <div v-if="form.assign_technician" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <InputLabel for="technician_name" value="Nombre del Técnico *" />
                        <InputText id="technician_name" v-model="form.technician_name" class="mt-1 w-full" />
                        <InputError :message="form.errors.technician_name" class="mt-2" />
                    </div>
                    <div>
                        <InputLabel value="Tipo de Comisión *" />
                        <SelectButton v-model="form.technician_commission_type" :options="commissionOptions"
                            optionLabel="label" optionValue="value" class="mt-1" />
                        <InputError :message="form.errors.technician_commission_type" class="mt-2" />
                    </div>
                    <div class="md:col-span-2">
                        <InputLabel for="technician_commission_value" value="Valor de la Comisión *" />
                        <InputNumber id="technician_commission_value" v-model="form.technician_commission_value"
                            class="w-full mt-1" :prefix="form.technician_commission_type === 'fixed' ? '$' : null"
                            :suffix="form.technician_commission_type === 'percentage' ? '%' : null" />
                        <InputError :message="form.errors.technician_commission_value" class="mt-2" />
                    </div>
                </div>
                <p v-else class="text-gray-500">Activa el interruptor para asignar un técnico y registrar su comisión.
                </p>
            </div>

            <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                <div class="flex items-center justify-between border-b pb-3 mb-4">
                    <h2 class="text-lg font-semibold">Detalles Adicionales</h2>
                    <Button @click="openCustomFieldManager" icon="pi pi-cog" text label="Gestionar" v-tooltip.left="'Gestionar campos personalizados'" />
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
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                <h2 class="text-lg font-semibold border-b pb-3 mb-4">Evidencia Fotográfica Inicial</h2>
                <div v-if="existingMedia.length > 0" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4 mb-4">
                    <div v-for="media in existingMedia" :key="media.id" class="relative group">
                        <img :src="media.original_url" alt="Evidencia existente"
                            class="rounded-lg object-cover w-full h-32">
                        <Button @click="removeExistingImage(media.id)" icon="pi pi-times" rounded text severity="danger"
                            class="!absolute top-1 right-1 bg-black/50 hover:!bg-black/70" />
                    </div>
                </div>
                <FileUpload name="initial_evidence_images[]" @select="onSelectImages" @remove="onRemoveImage"
                    :multiple="true" :show-upload-button="false" accept="image/*" :maxFileSize="2000000">
                    <template #empty>
                        <p>Arrastra y suelta imágenes para AÑADIR nueva evidencia.</p>
                    </template>
                </FileUpload>
                <InputError :message="form.errors.initial_evidence_images" class="mt-2" />
                <InputError :message="form.errors.deleted_media_ids" class="mt-2" />
            </div>

            <div class="flex justify-end sticky bottom-4">
                <Button type="submit" label="Guardar Cambios" :loading="form.processing" severity="warning"
                    class="shadow-lg" />
            </div>
        </form>

        <ManageCustomFields ref="manageFieldsComponent" module="service_orders"
            :definitions="props.customFieldDefinitions" />

        <!-- Modal de Variantes -->
        <SelectVariantModal v-model:visible="showVariantModal" :product="productForVariantSelection"
            @variant-selected="handleVariantSelected" />

        <ConfirmPopup group="concept-delete" />
    </AppLayout>
</template>