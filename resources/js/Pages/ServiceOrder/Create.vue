<script setup>
import { ref, computed, watch } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import PatternLock from '@/Components/PatternLock.vue';
import ManageCustomFields from '@/Components/ManageCustomFields.vue';
import StartSessionModal from '@/Components/StartSessionModal.vue';

const props = defineProps({
    customFieldDefinitions: Array,
    customers: Array,
    products: Array,
    services: Array,
    errors: Object,
    availableCashRegisters: Array,
});

const page = usePage();
const activeSession = computed(() => page.props.activeSession);
const isStartSessionModalVisible = ref(false);
const sessionModalAwaitingSubmit = ref(false);

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Ódenes de Servicio', url: route('service-orders.index') },
    { label: 'Crear Orden' }
]);

const form = useForm({
    // --- Campos de Cliente ---
    customer_id: '',
    customer_name: '',
    customer_phone: '',
    customer_email: '',
    create_customer: false,
    credit_limit: 0,
    // --- Otros campos ---
    item_description: '',
    reported_problems: '',
    promised_at: null,
    final_total: 0,
    custom_fields: {},
    initial_evidence_images: [],
    items: [],
    assign_technician: false,
    technician_name: '',
    technician_commission_type: 'percentage',
    technician_commission_value: null,
    cash_register_session_id: null,
});

// ... (toda la lógica de items, clientes, técnico y campos personalizados se mantiene igual)
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
        itemable_id: null, itemable_type: null, description: '',
        quantity: 1, unit_price: 0, line_total: 0,
    };
    if (typeof selectedItem.value === 'object' && selectedItem.value !== null) {
        itemToAdd = { ...itemToAdd, itemable_id: selectedItem.value.id, itemable_type: selectedItem.value.itemable_type, description: selectedItem.value.name, unit_price: selectedItem.value.price };
    } else if (typeof selectedItem.value === 'string') {
        itemToAdd = { ...itemToAdd, itemable_id: 0, description: selectedItem.value };
    } else { return; }
    form.items.push(itemToAdd);
    selectedItem.value = null;
};
const removeItem = (index) => form.items.splice(index, 1);
watch(() => form.items, (newItems) => {
    let total = 0;
    newItems.forEach(item => { total += (item.quantity || 0) * (item.unit_price || 0); item.line_total = (item.quantity || 0) * (item.unit_price || 0); });
    form.final_total = total;
}, { deep: true });

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

const isNewCustomer = computed(() => form.customer_name && !form.customer_id);

watch(() => form.customer_name, (newValue) => {
    if (form.customer_id) {
        const selectedCustomer = props.customers.find(c => c.id === form.customer_id);
        if (!selectedCustomer || selectedCustomer.name !== newValue) {
            form.customer_id = '';
            form.create_customer = true;
            form.credit_limit = 0;
        }
    }
});

watch(() => form.create_customer, (newValue) => {
    if (!newValue) {
        form.credit_limit = 0;
    }
});

const commissionOptions = ref([{ label: 'Porcentaje (%)', value: 'percentage' }, { label: 'Monto Fijo ($)', value: 'fixed' }]);
watch(() => form.assign_technician, (newValue) => {
    if (!newValue) { form.technician_name = ''; form.technician_commission_type = 'percentage'; form.technician_commission_value = null; }
});

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

const manageFieldsComponent = ref(null);
const openCustomFieldManager = () => {
    if (manageFieldsComponent.value) {
        manageFieldsComponent.value.open();
    }
};

const onSelectImages = (event) => form.initial_evidence_images = [...form.initial_evidence_images, ...event.files];
const onRemoveImage = (event) => form.initial_evidence_images = form.initial_evidence_images.filter(img => img.objectURL !== event.file.objectURL);

// --- INICIO DE CORRECCIÓN ---
const submit = () => {
    // 1. Verificar si hay una sesión activa ANTES de enviar.
    if (!activeSession.value) {
        // 2. Si no hay sesión, levantar un flag y mostrar el modal.
        sessionModalAwaitingSubmit.value = true;
        isStartSessionModalVisible.value = true;
        return; // Detener el envío del formulario.
    }
    
    // 3. Si hay sesión, agregar su ID al formulario y enviarlo.
    form.cash_register_session_id = activeSession.value.id;
    form.post(route('service-orders.store'));
};

// 4. Observar si la sesión activa cambia (es decir, después de que se abre en el modal).
watch(activeSession, (newSession) => {
    // 5. Si la sesión ahora existe y estábamos esperando para enviar, volvemos a llamar a submit().
    if (newSession && sessionModalAwaitingSubmit.value) {
        sessionModalAwaitingSubmit.value = false; // Resetear el flag
        submit(); // Ahora sí se enviará el formulario.
    }
});
// --- FIN DE CORRECCIÓN ---

</script>

<template>
    <Head title="Crear Orden de Servicio" />
    <AppLayout>
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        <div class="mt-4">
            <h1 class="text-2xl font-bold">Registrar Nueva Orden de Servicio</h1>
        </div>

        <form @submit.prevent="submit" class="mt-6 max-w-4xl mx-auto space-y-6">
            <!-- (Todo el contenido del formulario se mantiene igual) -->
            <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                <h2 class="text-lg font-semibold border-b pb-3 mb-4">Información Principal</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <InputLabel for="customer_name" value="Nombre del Cliente *" />
                       <AutoComplete v-model="form.customer_name" :suggestions="filteredCustomers" @complete="searchCustomer" field="name" @item-select="onCustomerSelect" inputClass="w-full" class="w-full mt-1" inputId="customer_name">
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
                    
                    <div v-if="isNewCustomer" class="md:col-span-2 bg-blue-50 dark:bg-blue-900/20 p-4 rounded-lg space-y-4">
                        <div class="flex items-center justify-between">
                            <span class="font-medium text-sm text-blue-800 dark:text-blue-200">Este parece ser un cliente nuevo. ¿Deseas agregarlo a tus registros?</span>
                            <ToggleSwitch v-model="form.create_customer" inputId="create_customer" />
                        </div>
                        <div v-if="form.create_customer" class="transition-all">
                            <InputLabel for="credit_limit" value="Asignar Límite de Crédito" />
                            <InputNumber id="credit_limit" v-model="form.credit_limit" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" />
                            <InputError :message="form.errors.credit_limit" class="mt-2" />
                        </div>
                    </div>
                    
                    <div>
                        <InputLabel for="customer_email" value="Correo Electrónico" />
                        <InputText id="customer_email" v-model="form.customer_email" class="mt-1 w-full" />
                        <InputError :message="form.errors.customer_email" class="mt-2" />
                    </div>
                    <div>
                        <InputLabel for="promised_at" value="Fecha Promesa de Entrega" />
                        <Calendar id="promised_at" v-model="form.promised_at" class="w-full mt-1" dateFormat="dd/mm/yy" />
                        <InputError :message="form.errors.promised_at" class="mt-2" />
                    </div>
                    <div class="md:col-span-2">
                        <InputLabel for="item_description" value="Descripción del Equipo *" />
                        <InputText id="item_description" v-model="form.item_description" class="mt-1 w-full" placeholder="Ej: iPhone 13 Pro, 256GB, Azul Sierra" />
                        <InputError :message="form.errors.item_description" class="mt-2" />
                    </div>
                    <div class="md:col-span-2">
                        <InputLabel for="reported_problems" value="Falla o Problema Reportado por el Cliente *" />
                        <Textarea id="reported_problems" v-model="form.reported_problems" rows="3" class="mt-1 w-full" />
                        <InputError :message="form.errors.reported_problems" class="mt-2" />
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
                                     class="w-full mt-1"
                                     :prefix="form.technician_commission_type === 'fixed' ? '$' : null"
                                     :suffix="form.technician_commission_type === 'percentage' ? '%' : null" />
                        <InputError :message="form.errors.technician_commission_value" class="mt-2" />
                    </div>
                </div>
                <p v-else class="text-gray-500">Activa el interruptor para asignar un técnico y registrar su comisión.</p>
            </div>

            <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                <div class="flex items-center justify-between border-b pb-3 mb-4">
                    <h2 class="text-lg font-semibold">Detalles adicionales</h2>
                    <Button @click="openCustomFieldManager" icon="pi pi-cog" text rounded v-tooltip.left="'Gestionar campos personalizados'" />
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div v-for="field in customFieldDefinitions" :key="field.id">
                        <InputLabel :for="field.key" :value="field.name" />
                        <InputText v-if="field.type === 'text'" :id="field.key" v-model="form.custom_fields[field.key]" class="mt-1 w-full" />
                        <InputNumber v-if="field.type === 'number'" :id="field.key" v-model="form.custom_fields[field.key]" class="w-full mt-1" />
                        <Textarea v-if="field.type === 'textarea'" :id="field.key" v-model="form.custom_fields[field.key]" rows="2" class="mt-1 w-full" />
                        <ToggleSwitch v-if="field.type === 'boolean'" :id="field.key" v-model="form.custom_fields[field.key]" class="mt-1" />
                        <PatternLock v-if="field.type === 'pattern'" :id="field.key" v-model="form.custom_fields[field.key]" class="mt-1" />
                        <Dropdown v-if="field.type === 'select'" :id="field.key" v-model="form.custom_fields[field.key]" :options="field.options" class="mt-1 w-full" placeholder="Selecciona una opción" />
                        <div v-if="field.type === 'checkbox'" class="flex flex-col gap-2 mt-2">
                            <div v-for="option in field.options" :key="option" class="flex items-center">
                                <Checkbox :inputId="`${field.key}-${option}`" v-model="form.custom_fields[field.key]" :value="option" />
                                <label :for="`${field.key}-${option}`" class="ml-2"> {{ option }} </label>
                            </div>
                        </div>
                        <InputError :message="form.errors[`custom_fields.${field.key}`]" class="mt-2" />
                    </div>
                    <p v-if="!customFieldDefinitions.length" class="col-span-full text-center text-gray-500">
                        Actualmente no tienes ningún campo adicional, pero puedes agregar los que requieras
                        haciendo clic en el ícono de engranaje (<i class="pi pi-cog"></i>) en la parte superior derecha.
                    </p>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                 <h2 class="text-lg font-semibold border-b pb-3 mb-4">Refacciones y Mano de Obra</h2>
                <div class="flex gap-2 mb-4">
                    <AutoComplete v-model="selectedItem" :suggestions="filteredItems" @complete="searchItems" field="name" optionLabel="name" placeholder="Busca o escribe un concepto..." class="w-full" dropdown>
                        <template #option="slotProps">
                            <div>{{ slotProps.option.name }}<Tag :value="slotProps.option.type" /></div>
                        </template>
                    </AutoComplete>
                    <Button @click="addItem" icon="pi pi-plus" label="Agregar" :disabled="!selectedItem" />
                </div>
                <DataTable :value="form.items" class="p-datatable-sm">
                    <template #empty><div class="text-center p-4">No se han agregado refacciones o servicios.</div></template>
                    <Column field="description" header="Descripción"><template #body="{ index }"><InputText v-model="form.items[index].description" class="w-full" /></template></Column>
                    <Column field="quantity" header="Cantidad" style="width: 10rem"><template #body="{ index }"><InputNumber v-model="form.items[index].quantity" class="w-full" showButtons buttonLayout="horizontal" :step="1" :min="0" /></template></Column>
                    <Column field="unit_price" header="Precio Unit." style="width: 12rem"><template #body="{ index }"><InputNumber v-model="form.items[index].unit_price" mode="currency" currency="MXN" locale="es-MX" class="w-full" /></template></Column>
                    <Column field="line_total" header="Total"><template #body="{ data }">{{ new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(data.line_total) }}</template></Column>
                    <Column style="width: 4rem"><template #body="{ index }"><Button @click="removeItem(index)" icon="pi pi-trash" text rounded severity="danger" /></template></Column>
                </DataTable>
                <InputError :message="form.errors.items" class="mt-2" />
                <div class="flex justify-end mt-4">
                    <div class="w-full max-w-xs">
                        <InputLabel for="final_total" value="Total Final" class="font-bold" />
                        <InputNumber id="final_total" v-model="form.final_total" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" inputClass="!font-bold" />
                        <InputError :message="form.errors.final_total" class="mt-2" />
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                <h2 class="text-lg font-semibold border-b pb-3 mb-4">Evidencia Fotográfica Inicial (Máx. 5)</h2>
                <FileUpload name="initial_evidence_images[]" @select="onSelectImages" @remove="onRemoveImage" :multiple="true" :show-upload-button="false" accept="image/*" :maxFileSize="2000000">
                    <template #empty><p>Arrastra y suelta las imágenes del equipo al recibirlo.</p></template>
                </FileUpload>
                <InputError :message="form.errors.initial_evidence_images" class="mt-2" />
            </div>

            <div class="flex justify-end sticky bottom-4">
                <Button type="submit" label="Crear Orden" :loading="form.processing" severity="warning" class="shadow-lg" />
            </div>
        </form>

        <ManageCustomFields
            ref="manageFieldsComponent"
            module="service_orders"
            :definitions="props.customFieldDefinitions"
        />

        <StartSessionModal 
            :visible="isStartSessionModalVisible"
            :cash-registers="availableCashRegisters"
            @update:visible="isStartSessionModalVisible = $event"
        />
    </AppLayout>
</template>