<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Clientes', url: route('customers.index') },
    { label: 'Crear Cliente' }
]);

const form = useForm({
    name: '',
    company_name: '',
    email: '',
    phone: '',
    tax_id: '',
    credit_limit: 0,
    initial_balance: 0,
});

// Estado para controlar la dirección del saldo visualmente
const balanceDirection = ref('credit'); // 'credit' (+) o 'debit' (-)

const balanceDirectionOptions = [
    { label: 'Saldo a favor (Positivo)', value: 'credit', icon: 'pi pi-arrow-up' },
    { label: 'Saldo deudor (Negativo)', value: 'debit', icon: 'pi pi-arrow-down' }
];

const submit = () => {
    form.transform((data) => {
        // Aseguramos que el monto base sea positivo
        let finalBalance = Math.abs(Number(data.initial_balance));

        // Aplicamos el signo negativo si es deuda
        if (balanceDirection.value === 'debit') {
            finalBalance = -finalBalance;
        }

        return {
            ...data,
            initial_balance: finalBalance,
        }
    }).post(route('customers.store'));
};
</script>

<template>
    <AppLayout title="Crear cliente">
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        <div class="mt-4">
            <h1 class="text-2xl font-bold">Registrar nuevo cliente</h1>
        </div>
        <form @submit.prevent="submit" class="mt-6 max-w-2xl mx-auto bg-white p-6 rounded-lg shadow-md">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <InputLabel for="name" value="Nombre del cliente *" />
                    <InputText id="name" v-model="form.name" class="mt-1 w-full" />
                    <InputError :message="form.errors.name" class="mt-2" />
                </div>
                <div>
                    <InputLabel for="company_name" value="Nombre de la empresa" />
                    <InputText id="company_name" v-model="form.company_name" class="mt-1 w-full" />
                </div>
                <div>
                    <InputLabel for="phone" value="Teléfono" />
                    <InputText id="phone" v-model="form.phone" class="mt-1 w-full" />
                </div>
                <div>
                    <InputLabel for="email" value="Correo electrónico" />
                    <InputText id="email" v-model="form.email" type="email" class="mt-1 w-full" />
                    <InputError :message="form.errors.email" class="mt-2" />
                </div>
                <div>
                    <InputLabel for="tax_id" value="RFC" />
                    <InputText id="tax_id" v-model="form.tax_id" class="mt-1 w-full" />
                </div>
                
                <div>
                    <InputLabel for="credit_limit" value="Límite de crédito" />
                    <!-- Corregido vB-model a v-model -->
                    <InputNumber id="credit_limit" v-model="form.credit_limit" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" />
                </div>
                <div class="md:col-span-2 grid grid-cols-1 gap-6">
                    <!-- CAMBIO: Control de Saldo Inicial Mejorado -->
                    <div class="bg-gray-50 p-3 rounded-lg border border-gray-200">
                        <InputLabel value="Configuración de saldo inicial" class="mb-2 font-semibold text-gray-700" />
                        
                        <!-- Selector de Dirección -->
                        <SelectButton v-model="balanceDirection" :options="balanceDirectionOptions" optionLabel="label" optionValue="value" class="w-full mb-3" :allowEmpty="false">
                            <template #option="slotProps">
                                <div class="flex items-center gap-2 text-xs sm:text-sm" :class="{
                                    'text-green-600': slotProps.option.value === 'credit',
                                    'text-red-600': slotProps.option.value === 'debit'
                                }">
                                    <i :class="slotProps.option.icon"></i>
                                    <span>{{ slotProps.option.label }}</span>
                                </div>
                            </template>
                        </SelectButton>

                        <InputLabel for="initial_balance" value="Monto del saldo" />
                        <InputNumber id="initial_balance" v-model="form.initial_balance" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" :min="0" placeholder="$0.00" />
                        <InputError :message="form.errors.initial_balance" class="mt-2" />
                    </div>
                </div>

            </div>
            <div class="flex justify-end mt-6">
                <Button type="submit" label="Guardar cliente" :loading="form.processing" severity="warning" />
            </div>
        </form>
    </AppLayout>
</template>