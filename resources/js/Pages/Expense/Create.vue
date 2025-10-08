<script setup>
import { ref, watch, computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import CreateExpenseCategoryModal from './Partials/CreateExpenseCategoryModal.vue';
import StartSessionModal from '@/Components/StartSessionModal.vue';

const props = defineProps({
    categories: Array,
    bankAccounts: Array,
    availableCashRegisters: Array,
});

const page = usePage();
const hasActiveBranchSession = computed(() => page.props.branchHasActiveSession);


const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Gastos', url: route('expenses.index') },
    { label: 'Crear Gasto' }
]);

const form = useForm({
    folio: '',
    amount: null,
    expense_category_id: null,
    expense_date: new Date(),
    status: 'pagado',
    description: '',
    payment_method: 'efectivo',
    bank_account_id: null,
    take_from_cash_register: false,
});

const statusOptions = ref([
    { label: 'Pagado', value: 'pagado' },
    { label: 'Pendiente', value: 'pendiente' },
]);

const paymentMethodOptions = ref([
    { label: 'Efectivo', value: 'efectivo', icon: 'pi pi-money-bill' },
    { label: 'Tarjeta', value: 'tarjeta', icon: 'pi pi-credit-card' },
    { label: 'Transferencia', value: 'transferencia', icon: 'pi pi-arrows-h' },
]);

// --- LÓGICA MEJORADA ---
// Observa el método de pago para preseleccionar la cuenta favorita.
watch(() => form.payment_method, (newMethod) => {
    if (newMethod === 'efectivo') {
        form.bank_account_id = null;
    } else { // 'tarjeta' or 'transferencia'
        form.take_from_cash_register = false;

        // Busca la cuenta marcada como favorita para la sucursal actual.
        // La consulta en el controlador asegura que `branches[0]` contiene la info de la sucursal actual.
        const favoriteAccount = props.bankAccounts.find(account =>
            account.branches?.[0]?.pivot?.is_favorite
        );

        // Si se encuentra una cuenta favorita, se preselecciona. Si no, se limpia la selección.
        form.bank_account_id = favoriteAccount ? favoriteAccount.id : null;
    }
});

const localCategories = ref([...props.categories]);
const showCategoryModal = ref(false);
const handleNewCategory = (newCategory) => {
    localCategories.value.push(newCategory);
    form.expense_category_id = newCategory.id;
};

const showStartSessionModal = ref(false);

const submit = () => {
    form.post(route('expenses.store'));
};
</script>

<template>
    <!-- El template se mantiene igual, no necesita cambios -->

    <Head title="Crear Gasto" />
    <AppLayout>
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        <div class="mt-4">
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Registrar Nuevo Gasto</h1>
        </div>

        <form @submit.prevent="submit"
            class="mt-6 max-w-2xl mx-auto bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <InputLabel for="folio" value="Concepto" />
                    <InputText id="folio" v-model="form.folio" class="mt-1 w-full" />
                    <InputError :message="form.errors.folio" class="mt-2" />
                </div>
                <div>
                    <InputLabel for="expense_date" value="Fecha del Gasto *" />
                    <DatePicker id="expense_date" v-model="form.expense_date" class="w-full mt-1"
                        dateFormat="dd/mm/yy" />
                    <InputError :message="form.errors.expense_date" class="mt-2" />
                </div>
                <div class="mt-3">
                    <InputLabel for="amount" value="Monto *" />
                    <InputNumber id="amount" v-model="form.amount" mode="currency" currency="MXN" locale="es-MX"
                        class="w-full mt-1" />
                    <InputError :message="form.errors.amount" class="mt-2" />
                </div>
                <div>
                    <div class="flex justify-between items-center mb-1">
                        <InputLabel for="category" value="Categoría *" />
                        <Button @click="showCategoryModal = true" label="Nueva" icon="pi pi-plus" text size="small" />
                    </div>
                    <Select size="large" id="category" v-model="form.expense_category_id" :options="localCategories"
                        optionLabel="name" optionValue="id" placeholder="Selecciona una categoría" filter
                        class="w-full" />
                    <InputError :message="form.errors.expense_category_id" class="mt-2" />
                </div>

                <div class="md:col-span-2 space-y-4 p-4 border rounded-lg bg-gray-50 dark:bg-gray-900/50">
                    <h3 class="font-semibold text-gray-700 dark:text-gray-300">Detalles del Pago</h3>
                    <div>
                        <InputLabel for="payment_method" value="Método de Pago *" />
                        <SelectButton id="payment_method" v-model="form.payment_method" :options="paymentMethodOptions"
                            optionLabel="label" optionValue="value" class="mt-1 w-full">
                            <template #option="slotProps">
                                <i :class="[slotProps.option.icon, 'mr-2']"></i>
                                <span>{{ slotProps.option.label }}</span>
                            </template>
                        </SelectButton>
                        <InputError :message="form.errors.payment_method" class="mt-2" />
                    </div>

                    <div v-if="form.payment_method === 'efectivo'">
                        <div v-if="hasActiveBranchSession" class="flex items-center gap-3">
                            <ToggleSwitch v-model="form.take_from_cash_register" inputId="take_from_cash_register" />
                            <InputLabel for="take_from_cash_register">
                                ¿Tomar efectivo de la caja activa?
                            </InputLabel>
                        </div>
                        <div v-else
                            class="flex items-start justify-between p-3 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg">
                            <div class="flex items-start gap-3 w-[70%]">
                                <i class="pi pi-info-circle text-yellow-500 !text-xl"></i>
                                <span class="text-sm text-yellow-700 dark:text-yellow-300">
                                    Se requiere una sesión de caja activa para indicar que el dinero se toma de ahí.
                                </span>
                            </div>
                            <Button label="Abrir caja" icon="pi pi-inbox" size="small"
                                @click="showStartSessionModal = true" />
                        </div>
                        <InputError :message="form.errors.take_from_cash_register" class="mt-2" />
                    </div>

                    <div v-if="form.payment_method === 'tarjeta' || form.payment_method === 'transferencia'">
                        <InputLabel for="bank_account_id" value="Cuenta de Origen *" />
                        <Select size="large" id="bank_account_id" v-model="form.bank_account_id" :options="bankAccounts"
                            optionLabel="account_name" optionValue="id" placeholder="Selecciona una cuenta"
                            class="w-full mt-1">
                            <template #option="slotProps">
                                <div class="flex flex-col">
                                    <span>{{ slotProps.option.account_name }} ({{ slotProps.option.bank_name }})</span>
                                    <span class="text-xs text-gray-500">{{ slotProps.option.account_number }}</span>
                                </div>
                            </template>
                        </Select>
                        <InputError :message="form.errors.bank_account_id" class="mt-2" />
                    </div>
                </div>

                <div class="md:col-span-2">
                    <InputLabel for="status" value="Estatus" />
                    <Select size="large" id="status" v-model="form.status" :disabled="true" :options="statusOptions" optionLabel="label"
                        optionValue="value" class="w-full mt-1" />
                    <InputError :message="form.errors.status" class="mt-2" />
                </div>

                <div class="md:col-span-2">
                    <InputLabel for="description" value="Descripción" />
                    <Textarea id="description" v-model="form.description" rows="3" class="mt-1 w-full" />
                    <InputError :message="form.errors.description" class="mt-2" />
                </div>
            </div>
            <div class="flex justify-end mt-6">
                <Button type="submit" label="Guardar Gasto" :loading="form.processing" severity="warning" />
            </div>
        </form>

        <CreateExpenseCategoryModal v-model:visible="showCategoryModal" @created="handleNewCategory" />

        <StartSessionModal v-model:visible="showStartSessionModal" :cash-registers="availableCashRegisters" />
    </AppLayout>
</template>