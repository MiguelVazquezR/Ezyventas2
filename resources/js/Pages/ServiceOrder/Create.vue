<script setup>
import { ref, computed, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import PatternLock from '@/Components/PatternLock.vue';
import ManageCustomFields from '@/Components/ManageCustomFields.vue';
import StartSessionModal from '@/Components/StartSessionModal.vue';
import JoinSessionModal from '@/Components/JoinSessionModal.vue';
import { usePermissions } from '@/Composables';
import { useConfirm } from "primevue/useconfirm";

const props = defineProps({
    customFieldDefinitions: Array,
    customers: Array,
    products: Array,
    services: Array,
    errors: Object,
    userBankAccounts: Array,
});

const page = usePage();
const confirm = useConfirm();
const { hasPermission } = usePermissions();

const activeSession = computed(() => page.props.activeSession);
const joinableSessions = computed(() => page.props.joinableSessions);
const availableCashRegisters = computed(() => page.props.availableCashRegisters);

const isStartSessionModalVisible = ref(false);
const isJoinSessionModalVisible = ref(false);
const sessionModalAwaitingSubmit = ref(false);

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Ódenes de Servicio', url: route('service-orders.index') },
    { label: 'Crear Orden' }
]);

const form = useForm({
    customer_id: '',
    customer_name: '',
    customer_phone: '',
    customer_email: '',
    create_customer: false,
    credit_limit: 0,
    item_description: '',
    reported_problems: '',
    promised_at: null,
    items: [],
    subtotal: 0,
    discount_type: 'fixed',
    discount_value: 0,
    discount_amount: 0,
    final_total: 0,
    custom_fields: {},
    initial_evidence_images: [],
    assign_technician: false,
    technician_name: '',
    technician_commission_type: 'percentage',
    technician_commission_value: null,
    cash_register_session_id: null,
});

// --- LÓGICA MEJORADA PARA CÁLCULOS Y SUBTOTAL MANUAL ---
const manualSubtotalMode = ref(true);

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
    if (typeof selectedItem.value === 'object' && selectedItem.value !== null) {
        itemToAdd = {
            ...itemToAdd,
            itemable_id: selectedItem.value.id,
            itemable_type: selectedItem.value.itemable_type,
            description: selectedItem.value.name,
            unit_price: selectedItem.value.price
        };
    } else if (typeof selectedItem.value === 'string') {
        itemToAdd = {
            ...itemToAdd,
            itemable_id: 0,
            description: selectedItem.value
        };
    } else { return; }
    form.items.push(itemToAdd);
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
const discountTypeOptions = ref([{ label: 'Fijo ($)', value: 'fixed' }, { label: 'Porcentaje (%)', value: 'percentage' }]);

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

const onSelectImages = (event) => form.initial_evidence_images = event.files;
const onRemoveImage = (event) => form.initial_evidence_images = form.initial_evidence_images.filter(img => img.objectURL !== event.file.objectURL);

const submit = () => {
    if (activeSession.value) {
        form.cash_register_session_id = activeSession.value.id;
        form.post(route('service-orders.store'));
    } else if (joinableSessions.value && joinableSessions.value.length > 0) {
        sessionModalAwaitingSubmit.value = true;
        isJoinSessionModalVisible.value = true;
    } else {
        sessionModalAwaitingSubmit.value = true;
        isStartSessionModalVisible.value = true;
    }
};

watch(activeSession, (newSession) => {
    if (newSession && sessionModalAwaitingSubmit.value) {
        sessionModalAwaitingSubmit.value = false;
        submit();
    }
});
</script>

<template>
    <AppLayout title="Crear orden de servicio">
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        <div class="mt-4">
            <h1 class="text-2xl font-bold">Registrar nueva orden de servicio</h1>
        </div>

        <form @submit.prevent="submit" class="mt-6 max-w-4xl mx-auto space-y-6">
            <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                <h2 class="text-lg font-semibold border-b pb-3 mb-4">Información principal</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <InputLabel for="customer_name" value="Nombre del cliente *" />
                        <AutoComplete v-model="form.customer_name" :suggestions="filteredCustomers"
                            @complete="searchCustomer" field="name" @item-select="onCustomerSelect" inputClass="w-full"
                            class="w-full mt-1" inputId="customer_name">
                            <template #option="slotProps">
                                <div>{{ slotProps.option.name }}</div>
                                <div class="text-xs text-gray-500 ml-2">{{ slotProps.option.phone }}</div>
                            </template>
                        </AutoComplete>
                        <InputError :message="form.errors.customer_name" class="mt-2" />
                    </div>
                    <div>
                        <InputLabel for="customer_phone" value="Teléfono del cliente" />
                        <InputText id="customer_phone" v-model="form.customer_phone" class="mt-1 w-full" />
                    </div>

                    <div v-if="isNewCustomer"
                        class="md:col-span-2 bg-blue-50 dark:bg-blue-900/20 p-4 rounded-lg space-y-4">
                        <div class="flex items-center justify-between">
                            <span class="font-medium text-sm text-blue-800 dark:text-blue-200">Este parece ser un
                                cliente nuevo. ¿Deseas
                                agregarlo a tus registros?</span>
                            <ToggleSwitch v-model="form.create_customer" inputId="create_customer" />
                        </div>
                        <div v-if="form.create_customer" class="transition-all">
                            <InputLabel for="credit_limit" value="Asignar límite de crédito" />
                            <InputNumber id="credit_limit" v-model="form.credit_limit" mode="currency" currency="MXN"
                                locale="es-MX" class="w-full mt-1" />
                            <InputError :message="form.errors.credit_limit" class="mt-2" />
                        </div>
                    </div>

                    <div>
                        <InputLabel for="customer_email" value="Correo electrónico" />
                        <InputText id="customer_email" v-model="form.customer_email" class="mt-1 w-full" />
                        <InputError :message="form.errors.customer_email" class="mt-2" />
                    </div>
                    <div>
                        <InputLabel for="promised_at" value="Fecha promesa de entrega" />
                        <DatePicker id="promised_at" v-model="form.promised_at" class="w-full mt-1"
                            dateFormat="dd/mm/yy" />
                        <InputError :message="form.errors.promised_at" class="mt-2" />
                    </div>
                    <div class="md:col-span-2">
                        <InputLabel for="item_description" value="Descripción del equipo *" />
                        <InputText id="item_description" v-model="form.item_description" class="mt-1 w-full"
                            placeholder="Ej: iPhone 13 Pro, 256GB, Azul Sierra" />
                        <InputError :message="form.errors.item_description" class="mt-2" />
                    </div>
                    <div class="md:col-span-2">
                        <InputLabel for="reported_problems" value="Falla o problema reportado por el cliente *" />
                        <Textarea id="reported_problems" v-model="form.reported_problems" rows="3"
                            class="mt-1 w-full" />
                        <InputError :message="form.errors.reported_problems" class="mt-2" />
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                <h2 class="text-lg font-semibold border-b pb-3 mb-4">Refacciones y mano de obra</h2>
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
                            <SelectButton v-model="form.items[index].itemable_type" :options="itemTypeOptions"
                                optionLabel="label" optionValue="value" :allowEmpty="false"
                                :disabled="data.itemable_id !== 0 && data.itemable_id !== null" class="w-full" />
                            <div v-if="form.items[index].itemable_type === 'App\\Models\\Product' && form.items[index].itemable_id && form.items[index].itemable_id !== 0"
                                class="text-xs text-gray-500 dark:text-gray-400 italic mt-1 pl-1">
                                (Se descontarán {{ form.items[index].quantity || 0 }} unidad(es) del stock)
                            </div>
                        </template>
                    </Column>
                    <Column field="description" header="Descripción"><template #body="{ index }">
                            <InputText v-model="form.items[index].description" fluid class="w-full" />
                        </template>
                    </Column>
                    <Column field="quantity" header="Cantidad" style="width: 9.5rem"><template #body="{ index }">
                            <InputNumber v-model="form.items[index].quantity" fluid class="w-full" showButtons
                                buttonLayout="horizontal" :step="1" :min="1" />
                        </template>
                    </Column>
                    <Column field="unit_price" header="Precio unit." style="width: 9.5rem"><template #body="{ index }">
                            <InputNumber v-model="form.items[index].unit_price" @blur="checkUnitPrice(index)"
                                mode="currency" currency="MXN" locale="es-MX" fluid class="w-full" />
                        </template>
                    </Column>
                    <Column field="line_total" header="Total"><template #body="{ data }">{{ new
                        Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(data.line_total)
                            }}</template>
                    </Column>
                    <Column style="width: 4rem"><template #body="{ index, event }"><Button
                                @click="confirmRemoveItem($event, index)" icon="pi pi-trash" text rounded size="small"
                                severity="danger" /></template>
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
                                    @click="toggleSubtotalMode" :severity="manualSubtotalMode ? 'secondary' : 'success'"
                                    text rounded size="small"
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
                            <span>- {{ new Intl.NumberFormat('es-MX', {
                                style: 'currency', currency:
                                    'MXN'
                            }).format(form.discount_amount) }}</span>
                        </div>
                        <Divider class="!my-2" />
                        <!-- Total Final -->
                        <div class="flex justify-between items-center text-xl font-bold">
                            <span class="text-gray-800 dark:text-gray-200">TOTAL:</span>
                            <span>{{ new Intl.NumberFormat('es-MX', {
                                style: 'currency',
                                currency: 'MXN'
                            }).format(form.final_total) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                <div class="flex items-center justify-between border-b pb-3 mb-4">
                    <h2 class="text-lg font-semibold">Asignación de técnico</h2>
                    <ToggleSwitch v-model="form.assign_technician" inputId="assign_technician" />
                </div>
                <div v-if="form.assign_technician" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <InputLabel for="technician_name" value="Nombre del técnico *" />
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
                        <InputLabel for="technician_commission_value" value="Valor de la comisión *" />
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
                    <h2 class="text-lg font-semibold">Detalles adicionales</h2>
                    <Button v-if="hasPermission('services.orders.manage_custom_fields')" @click="openCustomFieldManager"
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
                    <p v-if="!customFieldDefinitions.length && hasPermission('services.orders.manage_custom_fields')"
                        class="col-span-full text-center text-gray-500">
                        Actualmente no tienes ningún campo adicional, pero puedes agregar los que requieras
                        haciendo clic en el ícono de engranaje (<i class="pi pi-cog"></i> Gestionar) en la parte
                        superior derecha.
                    </p>
                    <p v-else-if="!hasPermission('services.orders.manage_custom_fields')"
                        class="col-span-full text-center text-gray-500">
                        Actualmente no tienes ningún campo adicional, pero un administrador puede agregarlos cuando se
                        requiera.
                    </p>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                <h2 class="text-lg font-semibold border-b pb-3 mb-4">Evidencia fotográfica inicial (Máx. 5)</h2>
                <FileUpload name="initial_evidence_images[]" @select="onSelectImages" @remove="onRemoveImage"
                    :multiple="true" :show-upload-button="false" accept="image/*" :maxFileSize="2000000">
                    <template #empty>
                        <p>Arrastra y suelta las imágenes del equipo al recibirlo.</p>
                    </template>
                </FileUpload>
                <InputError :message="form.errors.initial_evidence_images" class="mt-2" />
            </div>

            <div class="flex justify-end sticky bottom-4">
                <Button type="submit" label="Crear orden" :loading="form.processing" severity="warning"
                    class="shadow-lg" />
            </div>
        </form>

        <ManageCustomFields ref="manageFieldsComponent" module="service_orders"
            :definitions="props.customFieldDefinitions" />

        <StartSessionModal v-model:visible="isStartSessionModalVisible" :cash-registers="availableCashRegisters"
            :user-bank-accounts="userBankAccounts" />
        <JoinSessionModal v-model:visible="isJoinSessionModalVisible" :sessions="joinableSessions" />

        <ConfirmPopup group="concept-delete" />
    </AppLayout>
</template>