<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';

const emit = defineEmits(['success']);

const visible = ref(false);

const form = useForm({
    rfc: '',
    razon_social: '',
    regimen_fiscal: '',
    postal_code: '',
    email: '',
});

const taxRegimeOptions = [
    { label: '601 - General de Ley Personas Morales', value: '601' },
    { label: '603 - Personas Morales con Fines no Lucrativos', value: '603' },
    { label: '605 - Sueldos y Salarios', value: '605' },
    { label: '606 - Arrendamiento', value: '606' },
    { label: '608 - Demás ingresos', value: '608' },
    { label: '612 - Personas Físicas con Actividades Empresariales', value: '612' },
    { label: '614 - Ingresos por intereses', value: '614' },
    { label: '616 - Sin obligaciones fiscales', value: '616' },
    { label: '620 - Sociedades Cooperativas', value: '620' },
    { label: '621 - Incorporación Fiscal', value: '621' },
    { label: '622 - Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras', value: '622' },
    { label: '626 - Régimen Simplificado de Confianza', value: '626' },
];

function open() {
    form.reset();
    form.clearErrors();
    visible.value = true;
}

function submit() {
    form.post(route('billing.settings.storeFiscalProfile'), {
        onSuccess: () => {
            visible.value = false;
            form.reset();
            emit('success');
        },
    });
}

defineExpose({ open });

const dialogPt = {
    root: { class: 'dark:!bg-[#232323] !border !border-gray-100 dark:!border-[#3a3a3a] !rounded-3xl !shadow-2xl !overflow-hidden' },
    header: { class: 'dark:!bg-[#232323] !border-b !border-gray-100 dark:!border-[#3a3a3a] !px-6 !py-5' },
    title: { class: '!text-lg !font-medium !text-gray-900 dark:!text-white !tracking-tight !m-0' },
    content: { class: 'dark:!bg-[#232323] !p-6 lg:!p-8' },
    closeButton: { class: '!hover:bg-gray-100 dark:!hover:bg-[#1a1a1a] !transition-colors !rounded-full !w-8 !h-8 !flex !items-center !justify-center' },
    closeButtonIcon: { class: 'dark:!text-gray-400 !text-sm' },
    mask: { class: '!bg-gray-900/60 dark:!bg-black/80' },
};

const selectPt = {
    root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a]' },
};
</script>

<template>
    <Dialog
        v-model:visible="visible"
        modal
        class="w-full max-w-lg mx-4"
        :pt="dialogPt"
    >
        <template #header>
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-full bg-primary-50 dark:bg-primary-900/20 text-primary-500 flex items-center justify-center flex-shrink-0 border border-primary-100 dark:border-primary-900/30">
                    <i class="pi pi-building !text-sm"></i>
                </div>
                <div>
                    <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">
                        Agregar perfil fiscal
                    </h2>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">
                        Nueva razón social para facturación
                    </p>
                </div>
            </div>
        </template>

        <form @submit.prevent="submit" class="flex flex-col gap-5 pt-2">
            <!-- RFC -->
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">RFC *</label>
                <InputText
                    v-model="form.rfc"
                    placeholder="Ej. XAXX010101000"
                    class="w-full"
                    :class="{ '!border-red-500': form.errors.rfc }"
                    maxlength="13"
                    :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 !transition-colors !py-3 !text-sm' } }"
                />
                <Message v-if="form.errors.rfc" severity="error" variant="simple" size="small">
                    {{ form.errors.rfc }}
                </Message>
            </div>

            <!-- Razón social -->
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Razón social *</label>
                <InputText
                    v-model="form.razon_social"
                    placeholder="Nombre o razón social del emisor"
                    class="w-full"
                    :class="{ '!border-red-500': form.errors.razon_social }"
                    :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 !transition-colors !py-3 !text-sm' } }"
                />
                <Message v-if="form.errors.razon_social" severity="error" variant="simple" size="small">
                    {{ form.errors.razon_social }}
                </Message>
            </div>

            <!-- Régimen fiscal -->
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Régimen fiscal *</label>
                <Select
                    v-model="form.regimen_fiscal"
                    :options="taxRegimeOptions"
                    optionLabel="label"
                    optionValue="value"
                    placeholder="Selecciona el régimen fiscal"
                    class="w-full"
                    :class="{ '!border-red-500': form.errors.regimen_fiscal }"
                    :pt="selectPt"
                />
                <Message v-if="form.errors.regimen_fiscal" severity="error" variant="simple" size="small">
                    {{ form.errors.regimen_fiscal }}
                </Message>
            </div>

            <!-- Código postal -->
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Código postal fiscal *</label>
                <InputText
                    v-model="form.postal_code"
                    placeholder="Ej. 44600"
                    class="w-full"
                    :class="{ '!border-red-500': form.errors.postal_code }"
                    maxlength="5"
                    :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 !transition-colors !py-3 !text-sm' } }"
                />
                <Message v-if="form.errors.postal_code" severity="error" variant="simple" size="small">
                    {{ form.errors.postal_code }}
                </Message>
            </div>

            <!-- Email -->
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Email de contacto fiscal *</label>
                <InputText
                    v-model="form.email"
                    type="email"
                    placeholder="Ej. facturacion@empresa.com"
                    class="w-full"
                    :class="{ '!border-red-500': form.errors.email }"
                    :pt="{ root: { class: '!rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-100 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 !transition-colors !py-3 !text-sm' } }"
                />
                <Message v-if="form.errors.email" severity="error" variant="simple" size="small">
                    {{ form.errors.email }}
                </Message>
            </div>

            <!-- Info -->
            <div class="bg-blue-50/50 dark:bg-blue-900/10 p-4 rounded-2xl border border-blue-100 dark:border-blue-900/30">
                <div class="flex items-start gap-3">
                    <i class="pi pi-info-circle !text-sm text-blue-500 mt-0.5"></i>
                    <div>
                        <p class="text-xs font-medium text-blue-700 dark:text-blue-400 m-0 mb-0.5">
                            Aprovisionamiento automático
                        </p>
                        <p class="text-[10px] text-blue-600/70 dark:text-blue-400/70 m-0 leading-relaxed">
                            Al guardar, se creará automáticamente una subcuenta en SW Smarter Web para este RFC.
                        </p>
                    </div>
                </div>
            </div>
        </form>

        <template #footer>
            <div class="flex justify-end items-center gap-3 w-full mt-4 pt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
                <Button
                    label="Cancelar"
                    text
                    @click="visible = false"
                    :disabled="form.processing"
                    class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold"
                />
                <Button
                    label="Guardar perfil"
                    icon="pi pi-save"
                    @click="submit"
                    :loading="form.processing"
                    class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-6 shadow-sm"
                    severity="primary"
                />
            </div>
        </template>
    </Dialog>
</template>
