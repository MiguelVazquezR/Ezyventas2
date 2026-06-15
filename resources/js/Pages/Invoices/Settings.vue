<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    billingSettings: {
        type: Object,
        default: null,
    },
});

// ──────────────────────────────────────
// Inertia form — pre-fill when editing
// ──────────────────────────────────────
const form = useForm({
    emitter_rfc: props.billingSettings?.emitter_rfc || '',
    emitter_legal_name: props.billingSettings?.emitter_legal_name || '',
    emitter_tax_regime: props.billingSettings?.emitter_tax_regime || '',
    emitter_postal_code: props.billingSettings?.emitter_postal_code || '',
    api_key: props.billingSettings?.api_key || '',
});

// ──────────────────────────────────────
// Emitter tax regime options (SAT)
// ──────────────────────────────────────
const taxRegimeOptions = [
    { label: '601 — General de Ley Personas Morales', value: '601' },
    { label: '603 — Personas Morales con Fines no Lucrativos', value: '603' },
    { label: '605 — Sueldos y Salarios', value: '605' },
    { label: '606 — Arrendamiento', value: '606' },
    { label: '608 — Demás ingresos', value: '608' },
    { label: '612 — Personas Físicas con Actividades Empresariales', value: '612' },
    { label: '614 — Ingresos por intereses', value: '614' },
    { label: '616 — Sin obligaciones fiscales', value: '616' },
    { label: '620 — Sociedades Cooperativas', value: '620' },
    { label: '621 — Incorporación Fiscal', value: '621' },
    { label: '622 — Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras', value: '622' },
    { label: '626 — Régimen Simplificado de Confianza', value: '626' },
];

// ──────────────────────────────────────
// Submit
// ──────────────────────────────────────
const submit = () => {
    form.put(route('invoices.updateSettings'));
};
</script>

<template>
    <AppLayout title="Configuración fiscal">
        <div class="p-4 md:p-6 lg:p-8 max-w-3xl mx-auto space-y-6">

            <!-- ════════════════════════════════════════
                 Main panel
                 ════════════════════════════════════════ -->
            <div class="bg-white p-6 lg:p-8 rounded-3xl border border-gray-100">

                <!-- Header -->
                <div class="flex items-center justify-between mb-8">
                    <div>
                        <h1 class="text-2xl font-light tracking-tight text-gray-900 m-0">
                            Configuración fiscal
                        </h1>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0 mt-1.5">
                            Datos del emisor para el timbrado de CFDI 4.0
                        </p>
                    </div>
                    <a
                        :href="route('invoices.index')"
                        class="flex items-center gap-1.5 text-xs text-gray-400 hover:text-gray-600 transition-colors no-underline"
                    >
                        <i class="pi pi-arrow-left !text-xs"></i>
                        Volver al historial
                    </a>
                </div>

                <!-- ════════════════════════════════════════
                     Form
                     ════════════════════════════════════════ -->
                <form @submit.prevent="submit" class="space-y-5">

                    <!-- RFC -->
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">
                            RFC *
                        </label>
                        <InputText
                            v-model="form.emitter_rfc"
                            placeholder="Ej. XAXX010101000"
                            class="w-full"
                            :class="{ '!border-red-400': form.errors.emitter_rfc }"
                            :pt="{
                                root: {
                                    class: '!rounded-2xl !bg-gray-50 !border-gray-200 focus:!border-primary-500 !transition-colors !py-2.5 !text-sm',
                                },
                            }"
                            maxlength="13"
                        />
                        <Message
                            v-if="form.errors.emitter_rfc"
                            severity="error"
                            variant="simple"
                            size="small"
                        >
                            {{ form.errors.emitter_rfc }}
                        </Message>
                    </div>

                    <!-- Razón social -->
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">
                            Razón social *
                        </label>
                        <InputText
                            v-model="form.emitter_legal_name"
                            placeholder="Nombre o razón social del emisor"
                            class="w-full"
                            :class="{ '!border-red-400': form.errors.emitter_legal_name }"
                            :pt="{
                                root: {
                                    class: '!rounded-2xl !bg-gray-50 !border-gray-200 focus:!border-primary-500 !transition-colors !py-2.5 !text-sm',
                                },
                            }"
                        />
                        <Message
                            v-if="form.errors.emitter_legal_name"
                            severity="error"
                            variant="simple"
                            size="small"
                        >
                            {{ form.errors.emitter_legal_name }}
                        </Message>
                    </div>

                    <!-- Régimen fiscal -->
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">
                            Régimen fiscal *
                        </label>
                        <Select
                            v-model="form.emitter_tax_regime"
                            :options="taxRegimeOptions"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Selecciona el régimen fiscal"
                            class="w-full"
                            :class="{ '!border-red-400': form.errors.emitter_tax_regime }"
                            :pt="{
                                root: {
                                    class: '!rounded-2xl !bg-gray-50 !border-gray-200 focus:!border-primary-500 !transition-colors !text-sm',
                                },
                            }"
                        />
                        <Message
                            v-if="form.errors.emitter_tax_regime"
                            severity="error"
                            variant="simple"
                            size="small"
                        >
                            {{ form.errors.emitter_tax_regime }}
                        </Message>
                    </div>

                    <!-- Código postal fiscal -->
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">
                            Código postal fiscal *
                        </label>
                        <InputText
                            v-model="form.emitter_postal_code"
                            placeholder="Ej. 44600"
                            class="w-full"
                            :class="{ '!border-red-400': form.errors.emitter_postal_code }"
                            :pt="{
                                root: {
                                    class: '!rounded-2xl !bg-gray-50 !border-gray-200 focus:!border-primary-500 !transition-colors !py-2.5 !text-sm',
                                },
                            }"
                            maxlength="5"
                        />
                        <Message
                            v-if="form.errors.emitter_postal_code"
                            severity="error"
                            variant="simple"
                            size="small"
                        >
                            {{ form.errors.emitter_postal_code }}
                        </Message>
                    </div>

                    <!-- Clave de API -->
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-400 m-0">
                            Clave de API provisional
                        </label>
                        <InputText
                            v-model="form.api_key"
                            placeholder="Clave de API del PAC (opcional)"
                            class="w-full"
                            :class="{ '!border-red-400': form.errors.api_key }"
                            :pt="{
                                root: {
                                    class: '!rounded-2xl !bg-gray-50 !border-gray-200 focus:!border-primary-500 !transition-colors !py-2.5 !text-sm',
                                },
                            }"
                            type="password"
                        />
                        <Message
                            v-if="form.errors.api_key"
                            severity="error"
                            variant="simple"
                            size="small"
                        >
                            {{ form.errors.api_key }}
                        </Message>
                        <p class="text-[10px] text-gray-400 m-0 mt-0.5">
                            La clave se almacena cifrada. Déjala en blanco si aún no cuentas con un proveedor de timbrado.
                        </p>
                    </div>

                    <!-- Submit -->
                    <div class="pt-4">
                        <Button
                            type="submit"
                            label="Guardar configuración"
                            icon="pi pi-check"
                            :loading="form.processing"
                            class="!rounded-full !text-sm"
                        />
                    </div>

                </form>
            </div>
        </div>
    </AppLayout>
</template>
