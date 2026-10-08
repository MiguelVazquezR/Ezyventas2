<script setup>
import { ref, onMounted } from 'vue';
import InputError from '@/Components/InputError.vue';

defineProps({
    form: Object,          // useForm instance
    saving: Boolean,
});

const emit = defineEmits(['add-branch', 'remove-branch', 'set-main-branch', 'open-hours', 'save-step']);

// --- Local UI state ---
const showBusinessDetails = ref(false);
const openBranchDetails = ref(null); // index of the branch whose details are expanded

// The business name is the only required field — focus it on mount.
const businessNameInput = ref(null);
onMounted(() => {
    businessNameInput.value?.$el?.focus();
});

const toggleBranchDetails = (index) => {
    openBranchDetails.value = openBranchDetails.value === index ? null : index;
};

const removeBranch = (index) => {
    if (openBranchDetails.value === index) {
        openBranchDetails.value = null;
    }
    emit('remove-branch', index);
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

const readonlyPt = {
    root: { class: 'w-full !rounded-xl !bg-gray-100 dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !py-2.5 !text-xs !text-gray-500' },
};
</script>

<template>
    <div class="p-5 lg:p-6 space-y-6">
        
        <!-- Nombre del negocio — el único dato necesario -->
        <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 lg:p-6 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Nombre de tu negocio *</label>
                <InputText
                    ref="businessNameInput"
                    v-model="form.subscription.commercial_name"
                    placeholder="Ej. Abarrotes La Esquina"
                    :invalid="!!form.errors['subscription.commercial_name']"
                    :pt="inputPt"
                />
                <InputError :message="form.errors['subscription.commercial_name']" />
                <p class="text-[11px] text-gray-500 dark:text-gray-400 m-0 mt-1">
                    Con este nombre aparecerá tu negocio en el sistema. Es el único dato necesario ahora.
                </p>
            </div>

            <!-- Detalles opcionales del negocio -->
            <button type="button" @click="showBusinessDetails = !showBusinessDetails"
                class="mt-4 inline-flex items-center gap-1.5 bg-transparent border-none p-0 m-0 cursor-pointer text-[12px] font-semibold text-primary-500 hover:text-primary-600 dark:text-primary-400 dark:hover:text-primary-300 transition-colors">
                <i class="pi !text-[10px]" :class="showBusinessDetails ? 'pi-chevron-down' : 'pi-chevron-right'"></i>
                Agregar más detalles del negocio (opcional)
            </button>

            <div v-if="showBusinessDetails"
                class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4 pt-4 border-t border-gray-100 dark:border-[#3a3a3a]">
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Razón social</label>
                    <InputText v-model="form.subscription.business_name" placeholder="Ej. Mi Empresa S.A. de C.V."
                        :invalid="!!form.errors['subscription.business_name']" :pt="inputPt" />
                    <InputError :message="form.errors['subscription.business_name']" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Teléfono principal</label>
                    <InputText v-model="form.subscription.contact_phone" placeholder="Ej. 33 1234 5678"
                        :invalid="!!form.errors['subscription.contact_phone']" :pt="inputPt" />
                    <InputError :message="form.errors['subscription.contact_phone']" />
                </div>
                <div class="md:col-span-2 flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Dirección fiscal / matriz</label>
                    <InputText v-model="form.subscription.address" placeholder="Calle, número, colonia..."
                        :invalid="!!form.errors['subscription.address']" :pt="inputPt" />
                    <InputError :message="form.errors['subscription.address']" />
                </div>
            </div>
        </div>

        <!-- Sucursales -->
        <div class="space-y-3">
            <h3 class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">
                <i class="pi pi-building mr-2 text-gray-400"></i>Sucursales
            </h3>
            <p class="text-[11px] text-gray-500 dark:text-gray-400 m-0">
                Tu sucursal principal ya está creada. Puedes renombrarla o agregar más.
            </p>

            <div v-for="(branch, index) in form.branches" :key="index"
                class="bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a] p-4">
                <div class="flex items-center gap-3">
                    <InputText v-model="branch.name" placeholder="Nombre de la sucursal"
                        class="flex-1 min-w-0" :invalid="!!form.errors[`branches.${index}.name`]" :pt="inputPt" />

                    <!-- Sucursal principal -->
                    <span v-if="form.branches.length === 1"
                        class="flex-shrink-0 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[9px] font-bold uppercase tracking-wider bg-primary-500/10 text-primary-600 dark:text-primary-400 border border-primary-500/20">
                        <i class="pi pi-star-fill !text-[8px]"></i>
                        Principal
                    </span>
                    <div v-else class="flex items-center gap-1.5 flex-shrink-0">
                        <RadioButton :inputId="'main_branch_' + index" :modelValue="branch.is_main" :value="true"
                            @change="emit('set-main-branch', index)" />
                        <label :for="'main_branch_' + index"
                            class="text-[10px] uppercase tracking-wider font-bold text-gray-500 m-0 cursor-pointer">
                            Principal
                        </label>
                    </div>

                    <Button v-if="form.branches.length > 1" icon="pi pi-trash" severity="danger" text rounded
                        @click="removeBranch(index)" class="!w-7 !h-7 !p-0 flex-shrink-0" />
                </div>
                <InputError :message="form.errors[`branches.${index}.name`]" />

                <!-- Detalles opcionales de la sucursal -->
                <button type="button" @click="toggleBranchDetails(index)"
                    class="mt-3 inline-flex items-center gap-1.5 bg-transparent border-none p-0 m-0 cursor-pointer text-[12px] font-semibold text-primary-500 hover:text-primary-600 dark:text-primary-400 dark:hover:text-primary-300 transition-colors">
                    <i class="pi !text-[10px]" :class="openBranchDetails === index ? 'pi-chevron-down' : 'pi-chevron-right'"></i>
                    Detalles de la sucursal (opcional)
                </button>

                <div v-if="openBranchDetails === index"
                    class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4 pt-4 border-t border-gray-100 dark:border-[#3a3a3a]">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Teléfono</label>
                        <InputText v-model="branch.contact_phone" :pt="inputPt" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Email</label>
                        <InputText v-model="branch.contact_email" :pt="inputPt" />
                    </div>
                    <div class="md:col-span-2 flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Dirección</label>
                        <InputText v-model="branch.address" :pt="inputPt" />
                    </div>
                    <div class="md:col-span-2 flex flex-col gap-1.5">
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Horario semanal</label>
                        <div class="flex gap-2">
                            <InputText :value="getHoursSummary(branch.operating_hours)" readonly
                                class="flex-1" :pt="readonlyPt" />
                            <Button label="Establecer horario" icon="pi pi-clock" severity="secondary" outlined
                                @click="emit('open-hours', index)" class="!rounded-xl !text-xs !uppercase !tracking-wider" />
                        </div>
                        <InputError :message="form.errors[`branches.${index}.operating_hours`]" />
                    </div>
                </div>
            </div>

            <Button label="Agregar otra sucursal" icon="pi pi-plus" severity="secondary" text
                @click="emit('add-branch')" class="!rounded-full !text-xs !uppercase !tracking-wider !px-3" />
        </div>

        <!-- Navegación -->
        <div class="flex justify-end pt-1">
            <Button label="Siguiente" icon="pi pi-angle-right" iconPos="right"
                @click="emit('save-step')" :loading="saving || form.processing"
                class="!rounded-full !px-5 !text-sm" />
        </div>
    </div>
</template>
