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
    // Estructura para el campo JSON de dirección
    address: {
        street: '',
        exterior_number: '',
        interior_number: '',
        neighborhood: '',
        zip_code: '',
        city: '',
        state: '',
        cross_streets: '', // Entre calles
        notes: ''          // Referencias adicionales
    }
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
        <form @submit.prevent="submit" class="mt-6 max-w-4xl mx-auto bg-white p-6 rounded-lg shadow-md">
            
            <!-- SECCIÓN 1: DATOS GENERALES -->
            <div class="mb-6">
                <h2 class="text-lg font-semibold text-gray-700 border-b pb-2 mb-4">Datos Generales</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <InputLabel for="name" value="Nombre del cliente *" />
                        <InputText id="name" v-model="form.name" class="mt-1 w-full" placeholder="Ej. Juan Pérez" />
                        <InputError :message="form.errors.name" class="mt-2" />
                    </div>
                    <div>
                        <InputLabel for="company_name" value="Nombre de la empresa" />
                        <InputText id="company_name" v-model="form.company_name" class="mt-1 w-full" placeholder="Ej. Abarrotes del Centro" />
                    </div>
                    <div>
                        <InputLabel for="phone" value="Teléfono" />
                        <InputText id="phone" v-model="form.phone" class="mt-1 w-full" placeholder="Ej. 55 1234 5678" />
                    </div>
                    <div>
                        <InputLabel for="email" value="Correo electrónico" />
                        <InputText id="email" v-model="form.email" type="email" class="mt-1 w-full" placeholder="cliente@correo.com" />
                        <InputError :message="form.errors.email" class="mt-2" />
                    </div>
                    <div>
                        <InputLabel for="tax_id" value="RFC" />
                        <InputText id="tax_id" v-model="form.tax_id" class="mt-1 w-full" />
                    </div>
                </div>
            </div>

            <!-- SECCIÓN 2: DIRECCIÓN (NUEVO) -->
            <div class="mb-6">
                <div class="flex items-center justify-between border-b pb-2 mb-4">
                    <h2 class="text-lg font-semibold text-gray-700">Domicilio / Dirección</h2>
                    <small class="text-gray-500">Útil para envíos y localización en mapa</small>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-6 gap-4">
                    <div class="md:col-span-4">
                        <InputLabel for="street" value="Calle" />
                        <InputText id="street" v-model="form.address.street" class="mt-1 w-full" />
                    </div>
                    <div class="md:col-span-1">
                        <InputLabel for="ext_num" value="No. Exterior" />
                        <InputText id="ext_num" v-model="form.address.exterior_number" class="mt-1 w-full" />
                    </div>
                    <div class="md:col-span-1">
                        <InputLabel for="int_num" value="No. Interior" />
                        <InputText id="int_num" v-model="form.address.interior_number" class="mt-1 w-full" />
                    </div>

                    <div class="md:col-span-2">
                        <InputLabel for="neighborhood" value="Colonia" />
                        <InputText id="neighborhood" v-model="form.address.neighborhood" class="mt-1 w-full" />
                    </div>
                    <div class="md:col-span-2">
                        <InputLabel for="city" value="Ciudad / Municipio" />
                        <InputText id="city" v-model="form.address.city" class="mt-1 w-full" />
                    </div>
                    <div class="md:col-span-1">
                        <InputLabel for="state" value="Estado" />
                        <InputText id="state" v-model="form.address.state" class="mt-1 w-full" />
                    </div>
                    <div class="md:col-span-1">
                        <InputLabel for="zip_code" value="C.P." />
                        <InputText id="zip_code" v-model="form.address.zip_code" class="mt-1 w-full" />
                    </div>
                    
                    <div class="md:col-span-6">
                        <InputLabel for="cross_streets" value="Entre calles y referencias" />
                        <Textarea id="cross_streets" v-model="form.address.cross_streets" rows="2" class="mt-1 w-full" placeholder="Entre Calle A y Calle B, fachada color..." />
                    </div>
                </div>
            </div>

            <!-- SECCIÓN 3: CRÉDITO Y SALDO -->
            <div class="mb-6">
                <h2 class="text-lg font-semibold text-gray-700 border-b pb-2 mb-4">Configuración Financiera</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <InputLabel for="credit_limit" value="Límite de crédito" />
                        <InputNumber id="credit_limit" v-model="form.credit_limit" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" />
                        <small class="text-gray-500">Monto máximo que el cliente puede deber.</small>
                    </div>

                    <!-- Control de Saldo Inicial Mejorado -->
                    <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                        <InputLabel value="Saldo Inicial" class="mb-2 font-semibold text-gray-700" />
                        
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

                        <div class="mt-2">
                            <InputLabel for="initial_balance" value="Monto" />
                            <InputNumber id="initial_balance" v-model="form.initial_balance" mode="currency" currency="MXN" locale="es-MX" class="w-full mt-1" :min="0" placeholder="$0.00" />
                            <InputError :message="form.errors.initial_balance" class="mt-2" />
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end mt-6 pt-4 border-t">
                <Button type="button" label="Cancelar" severity="secondary" text class="mr-2" @click="() => router.visit(route('customers.index'))" />
                <Button type="submit" label="Guardar cliente" :loading="form.processing" severity="primary" icon="pi pi-save" />
            </div>
        </form>
    </AppLayout>
</template>