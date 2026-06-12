<script setup>
import { ref, computed, watch } from 'vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    form: {
        type: Object,
        required: true,
    },
    customers: {
        type: Array,
        required: true,
    }
});

const filteredCustomers = ref();

const searchCustomer = (event) => {
    setTimeout(() => {
        if (!event.query.trim().length) { 
            filteredCustomers.value = [...props.customers]; 
        } else { 
            filteredCustomers.value = props.customers.filter((customer) => 
                customer.name.toLowerCase().startsWith(event.query.toLowerCase())
            ); 
        }
    }, 250);
};

const onCustomerSelect = (event) => {
    const customer = event.value;
    props.form.customer_id = customer.id;
    props.form.customer_name = customer.name;
    props.form.customer_phone = customer.phone;
    props.form.customer_email = customer.email;
    if (customer.address) { 
        props.form.customer_address = customer.address; 
    }
};

const isNewCustomer = computed(() => props.form.customer_name && !props.form.customer_id);

watch(() => props.form.customer_name, (newValue) => {
    if (props.form.customer_id) {
        const selectedCustomer = props.customers.find(c => c.id === props.form.customer_id);
        if (!selectedCustomer || selectedCustomer.name !== newValue) {
            props.form.customer_id = '';
            props.form.create_customer = true;
            props.form.credit_limit = 0;
        }
    }
});

watch(() => props.form.create_customer, (newValue) => {
    if (!newValue) {
        props.form.credit_limit = 0;
    }
});
</script>

<template>
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
                    <span class="font-medium text-sm text-blue-800 dark:text-blue-200">
                        Este parece ser un cliente nuevo. ¿Deseas agregarlo a tus registros?
                    </span>
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
</template>