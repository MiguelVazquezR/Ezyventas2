<script setup>
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    form: Object,          // useForm instance
    activeStep: Number,    // current step for navigation
    saving: Boolean,
});

const emit = defineEmits(['add-branch', 'remove-branch', 'set-main-branch', 'open-hours', 'save-step', 'go-back']);

// --- Quick-fill: copia datos de la suscripción a la primera sucursal ---
const quickFillFirstBranch = () => {
    const sub = props.form.subscription;
    const branch = props.form.branches[0];
    if (!branch) return;

    if (sub.contact_phone && !branch.contact_phone) {
        branch.contact_phone = sub.contact_phone;
    }
    if (sub.address && !branch.address) {
        branch.address = sub.address;
    }
};

const getHoursSummary = (hoursArray) => {
    if (!Array.isArray(hoursArray)) return 'Horario no configurado';
    const openDays = hoursArray.filter(d => d.open);
    if (openDays.length === 0) return 'Cerrado';
    if (openDays.length === 7) return 'Abierto todos los días';
    return `Abierto ${openDays.length} días`;
};

// --- Tesla UI PT ---
const inputPt = {
    root: { class: 'w-full !rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2.5 !text-sm !text-gray-900 dark:!text-white' },
};

const inputNumberPt = {
    input: { root: { class: 'w-full !rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2.5 !text-sm !text-gray-900 dark:!text-white' } },
};
</script>

<template>
    <div class="p-5 lg:p-6 space-y-6">
        
        <!-- Sección: Información general del Negocio -->
        <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 lg:p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
            <h3 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-5">
                <i class="pi pi-briefcase mr-2 text-gray-400"></i>Información general del negocio
            </h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Nombre comercial *</label>
                    <InputText v-model="form.subscription.commercial_name" placeholder="Ej. Mi Tienda"
                        :invalid="!!form.errors['subscription.commercial_name']" :pt="inputPt" />
                    <InputError :message="form.errors['subscription.commercial_name']" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Razón social (opcional)</label>
                    <InputText v-model="form.subscription.business_name" placeholder="Ej. Mi Empresa S.A. de C.V."
                        :invalid="!!form.errors['subscription.business_name']" :pt="inputPt" />
                    <InputError :message="form.errors['subscription.business_name']" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Teléfono principal</label>
                    <InputText v-model="form.subscription.contact_phone"
                        :invalid="!!form.errors['subscription.contact_phone']" :pt="inputPt" />
                    <InputError :message="form.errors['subscription.contact_phone']" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Dirección fiscal / matriz</label>
                    <InputText v-model="form.subscription.address" placeholder="Calle, Número, Colonia..."
                        :invalid="!!form.errors['subscription.address']" :pt="inputPt" />
                    <InputError :message="form.errors['subscription.address']" />
                </div>
            </div>
        </div>

        <!-- Sección: Sucursales -->
        <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 lg:p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
            <div class="flex justify-between items-center mb-5">
                <h3 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                    <i class="pi pi-building mr-2 text-gray-400"></i>Sucursales
                </h3>
                <span class="text-[9px] uppercase tracking-wider text-gray-400">
                    {{ form.branches.length }} {{ form.branches.length === 1 ? 'registrada' : 'registradas' }}
                </span>
            </div>

            <Message severity="info" :closable="false" class="mb-5 !rounded-xl !text-xs" :pt="{ content: { class: '!text-xs' } }">
                Registra las sucursales de tu negocio. Podrás añadir más en cualquier momento.
            </Message>

            <div v-for="(branch, index) in form.branches" :key="index"
                class="p-4 lg:p-5 rounded-2xl space-y-4 relative bg-white dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] mb-4"
            >
                <!-- Quick-fill link (solo primera sucursal) -->
                <div v-if="index === 0" class="flex justify-end">
                    <button
                        type="button"
                        @click="quickFillFirstBranch"
                        class="text-[10px] uppercase tracking-widest font-bold text-primary-500 hover:text-primary-400 transition-colors bg-transparent border-none cursor-pointer p-0 flex items-center gap-1"
                    >
                        <i class="pi pi-copy !text-[9px]"></i> Copiar datos del negocio
                    </button>
                </div>

                <Button v-if="form.branches.length > 1" icon="pi pi-trash" severity="danger" text rounded
                    @click="emit('remove-branch', index)" class="!absolute top-3 right-3 !w-7 !h-7 !p-0" />

                <div class="flex items-center gap-2">
                    <RadioButton :inputId="'main_branch_' + index" :modelValue="branch.is_main" :value="true"
                        @change="emit('set-main-branch', index)" />
                    <label :for="'main_branch_' + index" class="text-xs font-medium text-gray-700 dark:text-gray-300 m-0 cursor-pointer">
                        Marcar como sucursal principal
                    </label>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Nombre *</label>
                        <InputGroup :pt="{ root: { class: 'w-full' } }">
                            <InputGroupAddon :pt="{ root: { class: '!bg-gray-100 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-xs !text-gray-500 !rounded-l-xl' } }">Sucursal</InputGroupAddon>
                            <InputText v-model="branch.name" :invalid="!!form.errors[`branches.${index}.name`]"
                                :pt="{ root: { class: '!rounded-r-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2.5 !text-sm !text-gray-900 dark:!text-white' } }" />
                        </InputGroup>
                        <InputError :message="form.errors[`branches.${index}.name`]" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Teléfono</label>
                        <InputText v-model="branch.contact_phone" :pt="inputPt" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Email</label>
                        <InputText v-model="branch.contact_email" :pt="inputPt" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Dirección</label>
                        <InputText v-model="branch.address" :pt="inputPt" />
                    </div>
                    <div class="md:col-span-2 flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Horario semanal</label>
                        <div class="flex gap-2">
                            <InputText :value="getHoursSummary(branch.operating_hours)" readonly
                                class="flex-1"
                                :pt="{ root: { class: '!rounded-xl !bg-gray-100 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !py-2.5 !text-xs !text-gray-500' } }" />
                            <Button label="Establecer horario" icon="pi pi-clock" severity="contrast"
                                @click="emit('open-hours', index)" class="!rounded-xl !text-xs !uppercase !tracking-wider" />
                        </div>
                        <InputError :message="form.errors[`branches.${index}.operating_hours`]" />
                    </div>
                </div>
            </div>

            <Button label="Añadir otra sucursal" icon="pi pi-plus" severity="secondary" outlined
                @click="emit('add-branch')" class="!rounded-xl !text-xs !uppercase !tracking-wider" />
        </div>

        <!-- Navegación -->
        <div class="flex justify-end pt-2">
            <Button label="Siguiente" icon="pi pi-arrow-right" iconPos="right"
                @click="emit('save-step', 0)" :loading="saving || form.processing"
                class="!rounded-full !text-xs !uppercase !tracking-wider" />
        </div>
    </div>
</template>
