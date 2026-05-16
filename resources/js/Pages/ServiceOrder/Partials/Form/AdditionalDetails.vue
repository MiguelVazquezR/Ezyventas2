<script setup>
import { ref, watch } from 'vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import PatternLock from '@/Components/PatternLock.vue';
import ManageCustomFields from '@/Components/ManageCustomFields.vue';
import { usePermissions } from '@/Composables';

const props = defineProps({
    form: Object,
    customFieldDefinitions: Array,
});

const { hasPermission } = usePermissions();
const commissionOptions = ref([{ label: 'Porcentaje (%)', value: 'percentage' }, { label: 'Monto Fijo ($)', value: 'fixed' }]);

watch(() => props.form.assign_technician, (newValue) => {
    if (!newValue) { 
        props.form.technician_name = ''; 
        props.form.technician_commission_type = 'percentage'; 
        props.form.technician_commission_value = null; 
    }
});

const initializeCustomFields = (definitions) => {
    const newCustomFields = {};
    definitions.forEach(field => {
        if (props.form.custom_fields && props.form.custom_fields.hasOwnProperty(field.key)) {
            newCustomFields[field.key] = props.form.custom_fields[field.key];
        } else {
            newCustomFields[field.key] = field.type === 'checkbox' ? [] : (field.type === 'boolean' ? false : (field.type === 'pattern' ? [] : null));
        }
    });
    props.form.custom_fields = newCustomFields;
};

// Iniciar y vigilar los custom fields
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
</script>

<template>
    <div class="space-y-6">
        <!-- Asignación de Técnico -->
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
                    <InputLabel value="Tipo de comisión *" />
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
            <p v-else class="text-gray-500">
                Activa el interruptor para asignar un técnico y registrar su comisión.
            </p>
        </div>

        <!-- Detalles Adicionales (Campos Personalizados) -->
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
                <p v-else-if="!customFieldDefinitions.length && !hasPermission('services.orders.manage_custom_fields')"
                    class="col-span-full text-center text-gray-500">
                    Actualmente no tienes ningún campo adicional, pero un administrador puede agregarlos cuando se
                    requiera.
                </p>
            </div>
        </div>

        <ManageCustomFields ref="manageFieldsComponent" module="service_orders"
            :definitions="customFieldDefinitions" />
    </div>
</template>