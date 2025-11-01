<script setup>
import { ref, watch, computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import ManageExpenseCategoriesModal from '@/Components/ManageExpenseCategoriesModal.vue';
import StartSessionModal from '@/Components/StartSessionModal.vue';
import JoinSessionModal from '@/Components/JoinSessionModal.vue';
import { format } from 'date-fns';

const props = defineProps({
    categories: Array,
    userBankAccounts: Array,
});

const page = usePage();

// --- LÓGICA DE SESIÓN ---
const activeSession = computed(() => page.props.activeSession);
const joinableSessions = computed(() => page.props.joinableSessions);
const availableCashRegisters = computed(() => page.props.availableCashRegisters);

const isStartSessionModalVisible = ref(false);
const isJoinSessionModalVisible = ref(false);
const sessionModalAwaitingSubmit = ref(false);

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Gastos', url: route('expenses.index') },
    { label: 'Crear gasto' }
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
    cash_register_session_id: null,
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

watch(() => form.payment_method, (newMethod) => {
    if (newMethod === 'efectivo') {
        form.bank_account_id = null;
    } else {
        form.take_from_cash_register = false;
        const favoriteAccount = props.userBankAccounts.find(account =>
            account.branches?.[0]?.pivot?.is_favorite
        );
        form.bank_account_id = favoriteAccount ? favoriteAccount.id : null;
    }
});

const localCategories = ref([...props.categories]);
const showCategoryModal = ref(false);
const handleNewCategory = (newCategory) => {
    localCategories.value.push(newCategory);
    form.expense_category_id = newCategory.id;
};

const handleCategoryUpdate = (updatedCategory) => {
    const index = localCategories.value.findIndex(c => c.id === updatedCategory.id);
    if (index !== -1) {
        localCategories.value[index] = updatedCategory;
    }
};

const handleCategoryDelete = (deletedCategoryId) => {
    localCategories.value = localCategories.value.filter(c => c.id !== deletedCategoryId);
    if (form.expense_category_id === deletedCategoryId) {
        form.expense_category_id = null;
    }
};

const submit = () => {
    if (form.take_from_cash_register) {
        if (activeSession.value) {
            form.cash_register_session_id = activeSession.value.id;
            postForm();
        } else if (joinableSessions.value && joinableSessions.value.length > 0) {
            sessionModalAwaitingSubmit.value = true;
            isJoinSessionModalVisible.value = true;
        } else {
            sessionModalAwaitingSubmit.value = true;
            isStartSessionModalVisible.value = true;
        }
    } else {
        postForm();
    }
};

const postForm = () => {
    form.transform((data) => ({
        ...data,
        expense_date: data.expense_date ? format(data.expense_date, 'yyyy-MM-dd') : null,
    })).post(route('expenses.store'));
}

watch(activeSession, (newSession) => {
    if (newSession && sessionModalAwaitingSubmit.value) {
        sessionModalAwaitingSubmit.value = false;
        submit();
    }
});

</script>

<template>
    <AppLayout title="Crear gasto">
        <Breadcrumb :home="home" :model="breadcrumbItems" class="!bg-transparent !p-0" />
        <div class="mt-4">
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-200">Registrar nuevo gasto</h1>
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
                    <InputLabel for="expense_date" value="Fecha del gasto *" />
                    <Calendar id="expense_date" v-model="form.expense_date" class="w-full mt-1" dateFormat="dd/mm/yy" />
                    <InputError :message="form.errors.expense_date" class="mt-2" />
                </div>
                <div class="md:col-span-2">
                    <InputLabel for="amount" value="Monto *" />
                    <InputNumber id="amount" v-model="form.amount" mode="currency" currency="MXN" locale="es-MX"
                        class="w-full mt-1" />
                    <InputError :message="form.errors.amount" class="mt-2" />
                </div>
                <div>
                    <div class="flex justify-between items-center mb-1">
                        <InputLabel for="category" value="Categoría *" />
                        <Button @click="showCategoryModal = true" label="Gestionar" icon="pi pi-cog" text
                            size="small" />
                    </div>
                    <Select size="large" id="category" v-model="form.expense_category_id" :options="localCategories"
                        optionLabel="name" optionValue="id" placeholder="Selecciona una categoría" filter
                        class="w-full" />
                    <InputError :message="form.errors.expense_category_id" class="mt-2" />
                </div>

                <div class="md:col-span-2 space-y-4 p-4 border rounded-lg bg-gray-50 dark:bg-gray-900/50">
                    <h5 class="font-semibold text-gray-700 dark:text-gray-300">Detalles del pago</h5>
                    <div>
                        <InputLabel for="payment_method" value="Método de pago *" />
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
                        <div class="flex items-center gap-3">
                            <ToggleSwitch v-model="form.take_from_cash_register" inputId="take_from_cash_register" />
                            <InputLabel for="take_from_cash_register">
                                ¿Tomar efectivo de la caja activa?
                            </InputLabel>
                        </div>
                        <InputError :message="form.errors.take_from_cash_register" class="mt-2" />
                        <InputError :message="form.errors.cash_register_session_id" class="mt-2" />
                    </div>

                    <div v-if="form.payment_method === 'tarjeta' || form.payment_method === 'transferencia'">
                        <InputLabel for="bank_account_id" value="Cuenta de Origen *" />
                        <Select size="large" id="bank_account_id" v-model="form.bank_account_id"
                            :options="userBankAccounts" optionLabel="account_name" optionValue="id"
                            placeholder="Selecciona una cuenta" class="w-full mt-1">
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
                    <Select size="large" id="status" v-model="form.status" :disabled="true" :options="statusOptions"
                        optionLabel="label" optionValue="value" class="w-full mt-1" />
                    <InputError :message="form.errors.status" class="mt-2" />
                </div>

                <div class="md:col-span-2">
                    <InputLabel for="description" value="Descripción" />
                    <Textarea id="description" v-model="form.description" rows="3" class="mt-1 w-full" />
                    <InputError :message="form.errors.description" class="mt-2" />
                </div>
            </div>
            <div class="flex justify-end mt-6">
                <Button type="submit" label="Guardar gasto" :loading="form.processing" severity="warning" />
            </div>
        </form>

        <ManageExpenseCategoriesModal v-model:visible="showCategoryModal" @created="handleNewCategory"
            @updated="handleCategoryUpdate" @deleted="handleCategoryDelete" />

        <StartSessionModal v-model:visible="isStartSessionModalVisible" :cash-registers="availableCashRegisters"
            :user-bank-accounts="userBankAccounts" />
        <JoinSessionModal v-model:visible="isJoinSessionModalVisible" :sessions="joinableSessions" />
    </AppLayout>
</template>