<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    planItems: Array,
});

const form = useForm({
    business_name: '',
    commercial_name: '',
    contact_email: '',
    contact_phone: '',
    tax_id: '',
    address: '',
    admin_name: '',
    admin_email: '',
    admin_password: '',
    verify_email: true,
    complete_onboarding: true,
    limits: {},
    modules: {},
});

// ── Initialize defaults from planItems catalog ──────────────────────
function initDefaults() {
    const limitsObj = {};
    const modulesObj = {};

    props.planItems.forEach((item) => {
        if (item.type === 'limit') {
            limitsObj[item.key] = item.meta?.default_quantity || 0;
        }
        if (item.type === 'module') {
            modulesObj[item.key] = true;
        }
    });

    form.limits = limitsObj;
    form.modules = modulesObj;
}

initDefaults();

function submit() {
    form.post(route('admin.subscriptions.store'), {
        onSuccess: () => {
            // Toast is handled by controller flash message via AppLayout
        },
    });
}

// ── Tesla UI PT ────────────────────────────────────────────────────
const inputPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2.5 !text-sm text-gray-900 dark:text-white w-full' },
};

const inputNumberPt = {
    input: { root: { class: 'w-full min-w-0 !rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2.5 !text-sm text-gray-900 dark:text-white' } },
};

const togglePt = {
    root: { class: '!w-10 !h-5' },
    input: { class: '!rounded-full' },
};
</script>

<template>
    <AppLayout title="Nueva suscripción">
        <div class="p-4 md:p-6 lg:p-8 max-w-[1200px] mx-auto space-y-6">

            <!-- Back button -->
            <button
                @click="$inertia.visit(route('admin.subscriptions.index'))"
                class="flex items-center gap-2 text-xs uppercase tracking-widest font-bold text-gray-500 hover:text-gray-800 dark:hover:text-white transition-colors bg-transparent border-none cursor-pointer p-0"
            >
                <i class="pi pi-arrow-left !text-[10px]"></i> Volver al directorio
            </button>

            <!-- Main card -->
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">

                <!-- Header -->
                <div class="mb-8">
                    <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0">
                        Nueva suscripción
                    </h1>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.8)] animate-pulse"></span>
                        Registro manual de suscriptor y administrador
                    </p>
                </div>

                <form @submit.prevent="submit" class="space-y-6">

                    <!-- ═══ Section: Business info ═══ -->
                    <div class="bg-gray-50 dark:bg-[#1a1a1a] p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                        <h2 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 mb-5 flex items-center gap-2">
                            <i class="pi pi-building"></i> Información del negocio
                        </h2>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0" for="business_name">
                                    Razón social *
                                </label>
                                <InputText id="business_name" v-model="form.business_name" :pt="inputPt" required />
                                <Message v-if="form.errors.business_name" severity="error" variant="simple" size="small">
                                    {{ form.errors.business_name }}
                                </Message>
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0" for="commercial_name">
                                    Nombre comercial *
                                </label>
                                <InputText id="commercial_name" v-model="form.commercial_name" :pt="inputPt" required />
                                <Message v-if="form.errors.commercial_name" severity="error" variant="simple" size="small">
                                    {{ form.errors.commercial_name }}
                                </Message>
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0" for="contact_email">
                                    Correo de contacto
                                </label>
                                <InputText id="contact_email" v-model="form.contact_email" :pt="inputPt" />
                                <Message v-if="form.errors.contact_email" severity="error" variant="simple" size="small">
                                    {{ form.errors.contact_email }}
                                </Message>
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0" for="contact_phone">
                                    Teléfono de contacto
                                </label>
                                <InputText id="contact_phone" v-model="form.contact_phone" :pt="inputPt" />
                                <Message v-if="form.errors.contact_phone" severity="error" variant="simple" size="small">
                                    {{ form.errors.contact_phone }}
                                </Message>
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0" for="tax_id">
                                    RFC
                                </label>
                                <InputText id="tax_id" v-model="form.tax_id" :pt="inputPt" />
                                <Message v-if="form.errors.tax_id" severity="error" variant="simple" size="small">
                                    {{ form.errors.tax_id }}
                                </Message>
                            </div>

                            <div class="flex flex-col gap-1.5 md:col-span-2">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0" for="address">
                                    Dirección
                                </label>
                                <InputText id="address" v-model="form.address" :pt="inputPt" />
                                <Message v-if="form.errors.address" severity="error" variant="simple" size="small">
                                    {{ form.errors.address }}
                                </Message>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ Section: Admin user ═══ -->
                    <div class="bg-gray-50 dark:bg-[#1a1a1a] p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                        <h2 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 mb-5 flex items-center gap-2">
                            <i class="pi pi-user"></i> Administrador
                        </h2>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0" for="admin_name">
                                    Nombre completo *
                                </label>
                                <InputText id="admin_name" v-model="form.admin_name" :pt="inputPt" required />
                                <Message v-if="form.errors.admin_name" severity="error" variant="simple" size="small">
                                    {{ form.errors.admin_name }}
                                </Message>
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0" for="admin_email">
                                    Correo electrónico *
                                </label>
                                <InputText id="admin_email" v-model="form.admin_email" type="email" :pt="inputPt" required />
                                <Message v-if="form.errors.admin_email" severity="error" variant="simple" size="small">
                                    {{ form.errors.admin_email }}
                                </Message>
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0" for="admin_password">
                                    Contraseña *
                                </label>
                                <InputText id="admin_password" v-model="form.admin_password" type="password" :pt="inputPt" required />
                                <Message v-if="form.errors.admin_password" severity="error" variant="simple" size="small">
                                    {{ form.errors.admin_password }}
                                </Message>
                            </div>

                            <div class="flex items-center gap-3 pt-1">
                                <ToggleSwitch v-model="form.verify_email" :inputId="'verify-email'" :pt="togglePt" />
                                <label for="verify-email" class="text-xs text-gray-700 dark:text-gray-300 cursor-pointer select-none">
                                    Verificar correo automáticamente
                                </label>
                            </div>

                            <div class="flex items-center gap-3 pt-1">
                                <ToggleSwitch v-model="form.complete_onboarding" :inputId="'complete-onboarding'" :pt="togglePt" />
                                <label for="complete-onboarding" class="text-xs text-gray-700 dark:text-gray-300 cursor-pointer select-none">
                                    Marcar onboarding como completado
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ Section: Modules ═══ -->
                    <div class="bg-gray-50 dark:bg-[#1a1a1a] p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                        <h2 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 mb-5 flex items-center gap-2">
                            <i class="pi pi-box"></i> Módulos activos
                        </h2>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                            <div
                                v-for="modItem in planItems.filter(p => p.type === 'module')"
                                :key="modItem.key"
                                class="flex items-center justify-between py-2.5 px-4 rounded-xl bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a]"
                            >
                                <label
                                    class="text-xs text-gray-700 dark:text-gray-300 cursor-pointer select-none"
                                    :for="'mod-' + modItem.key"
                                >
                                    {{ modItem.name }}
                                </label>
                                <ToggleSwitch
                                    :inputId="'mod-' + modItem.key"
                                    v-model="form.modules[modItem.key]"
                                    :pt="togglePt"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- ═══ Section: Limits ═══ -->
                    <div class="bg-gray-50 dark:bg-[#1a1a1a] p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                        <h2 class="text-xs uppercase tracking-widest font-bold text-gray-500 m-0 mb-5 flex items-center gap-2">
                            <i class="pi pi-chart-pie"></i> Recursos (límites)
                        </h2>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div
                                v-for="limItem in planItems.filter(p => p.type === 'limit')"
                                :key="limItem.key"
                                class="flex flex-col gap-1.5"
                            >
                                <label
                                    class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 truncate"
                                    :title="limItem.name"
                                >
                                    {{ limItem.name }}
                                </label>
                                <InputNumber v-model="form.limits[limItem.key]" fluid :pt="inputNumberPt" />
                                <small class="text-[9px] text-gray-400">(-1 = Ilimitado)</small>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ Actions ═══ -->
                    <div class="flex gap-3 justify-end pt-4 border-t border-gray-100 dark:border-[#3a3a3a]">
                        <Button
                            label="Cancelar"
                            icon="pi pi-times"
                            @click="$inertia.visit(route('admin.subscriptions.index'))"
                            severity="secondary"
                            outlined
                            class="!rounded-xl !text-xs !uppercase !tracking-wider"
                            :disabled="form.processing"
                        />
                        <Button
                            label="Crear suscripción"
                            icon="pi pi-check"
                            type="submit"
                            severity="primary"
                            class="!rounded-xl !text-xs !uppercase !tracking-wider"
                            :loading="form.processing"
                        />
                    </div>

                </form>

            </div>
        </div>
    </AppLayout>
</template>