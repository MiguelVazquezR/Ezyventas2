<script setup>
import { ref, computed, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputError from '@/Components/InputError.vue';
import StartSessionModal from '@/Components/StartSessionModal.vue';
import JoinSessionModal from '@/Components/JoinSessionModal.vue';

// Lógica de navegación
import FormNavigationSidebar from '@/Components/FormNavigationSidebar.vue';
import { useScrollspy } from '@/Composables/useScrollspy';

// Partials refactorizados
import MainInformation from './Partials/Form/MainInformation.vue';
import OrderItems from './Partials/Form/OrderItems.vue';
import AdditionalDetails from './Partials/Form/AdditionalDetails.vue';

const props = defineProps({
    customFieldDefinitions: Array,
    customers: Array,
    products: Array,
    services: Array,
    errors: Object,
    userBankAccounts: Array,
});

const page = usePage();
const activeSession = computed(() => page.props.activeSession);
const joinableSessions = computed(() => page.props.joinableSessions);
const availableCashRegisters = computed(() => page.props.availableCashRegisters);

const isStartSessionModalVisible = ref(false);
const isJoinSessionModalVisible = ref(false);
const sessionModalAwaitingSubmit = ref(false);

const home = ref({ icon: 'pi pi-home', url: route('dashboard') });
const breadcrumbItems = ref([
    { label: 'Órdenes de Servicio', url: route('service-orders.index') },
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

// --- Lógica del menú lateral ---
const formSections = [
    { id: 'general', label: 'Información principal' },
    { id: 'items', label: 'Refacciones y mano de obra' },
    { id: 'additional', label: 'Técnico y detalles adicionales' },
    { id: 'evidence', label: 'Evidencia inicial' }
];
const { activeSection, scrollTo } = useScrollspy(formSections.map(s => s.id));

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

        <div class="mt-6 flex flex-col md:flex-row gap-6 items-start relative">
            
            <FormNavigationSidebar :sections="formSections" :activeSection="activeSection" @scrollTo="scrollTo" />

            <!-- Contenedor del Formulario -->
            <div class="w-full md:w-3/4">
                <form @submit.prevent="submit" class="space-y-6">
                    
                    <div id="general">
                        <MainInformation :form="form" :customers="props.customers" />
                    </div>
                    
                    <div id="items">
                        <OrderItems :form="form" :products="props.products" :services="props.services" />
                    </div>

                    <div id="additional">
                        <AdditionalDetails :form="form" :customFieldDefinitions="props.customFieldDefinitions" />
                    </div>

                    <!-- Evidencia Fotográfica Inicial -->
                    <div id="evidence" class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                        <h2 class="text-lg font-semibold border-b pb-3 mb-4">Evidencia fotográfica inicial (Máx. 5)</h2>
                        <FileUpload name="initial_evidence_images[]" @select="onSelectImages" @remove="onRemoveImage"
                            :multiple="true" :show-upload-button="false" accept="image/*" :maxFileSize="2000000">
                            <template #empty>
                                <p>Arrastra y suelta las imágenes del equipo al recibirlo.</p>
                            </template>
                        </FileUpload>
                        <InputError :message="form.errors.initial_evidence_images" class="mt-2" />
                    </div>

                    <div class="flex justify-end sticky bottom-4 z-20">
                        <Button type="submit" label="Crear orden" icon="pi pi-check" :loading="form.processing" severity="primary" size="large" class="shadow-xl" />
                    </div>
                </form>
            </div>
        </div>

        <StartSessionModal v-model:visible="isStartSessionModalVisible" :cash-registers="availableCashRegisters" :user-bank-accounts="userBankAccounts" />
        <JoinSessionModal v-model:visible="isJoinSessionModalVisible" :sessions="joinableSessions" />
    </AppLayout>
</template>