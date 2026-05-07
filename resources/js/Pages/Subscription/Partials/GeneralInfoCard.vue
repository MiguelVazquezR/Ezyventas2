<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Textarea from 'primevue/textarea';
import InputMask from 'primevue/inputmask';
import Checkbox from 'primevue/checkbox';

const props = defineProps({
    subscription: Object,
    mainBranch: Object,
    pendingPayment: Object
});

const isEditModalVisible = ref(false);
const hoursModalVisible = ref(false);

const getAddressText = (addr) => {
    if (!addr) return 'Sin dirección registrada';
    if (typeof addr === 'object') {
        return addr.text || addr.line1 || 'Sin dirección registrada';
    }
    return addr; 
};

const getHoursSummary = (hoursArray) => {
    if (!Array.isArray(hoursArray)) return 'Horario no configurado';
    const openDays = hoursArray.filter(d => d.open);
    if (openDays.length === 0) return 'Cerrado';
    if (openDays.length === 7) return 'Abierto todos los días';
    return `Abierto ${openDays.length} días`;
};

const formatDate = (dateString) => new Date(dateString).toLocaleDateString('es-MX', { year: 'numeric', month: 'long', day: 'numeric' });
const getStatusTagSeverity = (status) => ({ activo: 'success', expirado: 'warn', suspendido: 'danger' })[status] || 'info';

const createDefaultHours = () => {
    const daysOfWeek = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
    return daysOfWeek.map(day => ({ day: day, open: false, from: '09:00', to: '18:00' }));
};

const infoForm = useForm({
    commercial_name: '',
    business_name: '',
    contact_phone: '',
    address: '',
    operating_hours: [],
});

const openEditInfoModal = () => {
    infoForm.commercial_name = props.subscription.commercial_name;
    infoForm.business_name = props.subscription.business_name;
    infoForm.contact_phone = props.subscription.contact_phone || '';
    infoForm.address = getAddressText(props.subscription.address) === 'Sin dirección registrada' ? '' : getAddressText(props.subscription.address);
    
    if (props.mainBranch && Array.isArray(props.mainBranch.operating_hours) && props.mainBranch.operating_hours.length === 7) {
        infoForm.operating_hours = JSON.parse(JSON.stringify(props.mainBranch.operating_hours));
    } else {
        infoForm.operating_hours = createDefaultHours();
    }
    
    isEditModalVisible.value = true;
};

const submitInfoForm = () => {
    infoForm.put(route('subscription.update'), {
        onSuccess: () => {
            isEditModalVisible.value = false;
        },
    });
};

// --- TESLA UI PASS-THROUGH (PT) ---
const dialogPt = {
    root: { class: 'dark:bg-[#232323] border border-gray-100 dark:border-[#3a3a3a] rounded-3xl shadow-2xl overflow-hidden' },
    header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 py-5' },
    title: { class: 'text-lg font-medium text-gray-900 dark:text-white tracking-tight m-0' },
    content: { class: 'dark:bg-[#232323] p-6 lg:p-8 custom-scrollbar' },
    closeButton: { class: 'hover:bg-gray-100 dark:hover:bg-[#1a1a1a] transition-colors rounded-full w-8 h-8 flex items-center justify-center' },
    closeButtonIcon: { class: 'dark:text-gray-400 !text-sm' },
    mask: { class: 'bg-gray-900/60 dark:bg-black/80' }
};

const inputPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2.5 !text-sm w-full' }
};

const textareaPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3 !text-sm w-full' }
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[9px] !uppercase !tracking-widest !font-bold' }
};
</script>

<template>
    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col">
        
        <!-- Header -->
        <div class="mb-6 flex justify-between items-start gap-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                    <i class="pi pi-building !text-sm text-blue-500"></i>
                </div>
                <div>
                    <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Información general</h2>
                    <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1 m-0">Datos de la empresa y sucursal principal</p>
                </div>
            </div>
            <Button icon="pi pi-pencil" text rounded @click="openEditInfoModal"
                class="!w-8 !h-8 !text-gray-400 hover:!bg-gray-200 dark:hover:!bg-[#2a2a2a] !transition-colors"
                v-tooltip.bottom="'Editar información'" />
        </div>

        <!-- Contenido Principal -->
        <div class="flex-grow flex flex-col">
            <ul class="m-0 p-0 list-none space-y-4">
                <li class="flex flex-col sm:flex-row justify-between sm:items-center gap-1 sm:gap-4 border-b border-gray-100 dark:border-[#2a2a2a] pb-4">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 shrink-0">Nombre comercial</span>
                    <span class="font-medium text-sm text-gray-900 dark:text-gray-100 m-0 text-left sm:text-right">{{ subscription.commercial_name }}</span>
                </li>
                
                <li class="flex flex-col sm:flex-row justify-between sm:items-center gap-1 sm:gap-4 border-b border-gray-100 dark:border-[#2a2a2a] pb-4">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 shrink-0">Razón social</span>
                    <span class="font-medium text-sm text-gray-900 dark:text-gray-100 m-0 text-left sm:text-right">{{ subscription.business_name || 'N/A' }}</span>
                </li>
                
                <li class="flex justify-between items-center border-b border-gray-100 dark:border-[#2a2a2a] pb-4">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 shrink-0">Teléfono</span>
                    <span class="font-mono font-medium text-sm text-gray-900 dark:text-gray-100 m-0">{{ subscription.contact_phone || 'N/A' }}</span>
                </li>
                
                <li class="flex flex-col sm:flex-row justify-between sm:items-center gap-1 sm:gap-4 border-b border-gray-100 dark:border-[#2a2a2a] pb-4">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 shrink-0">Dirección</span>
                    <span class="font-medium text-sm text-gray-900 dark:text-gray-100 m-0 text-left sm:text-right max-w-full sm:max-w-[60%] truncate" :title="getAddressText(subscription.address)">
                        {{ getAddressText(subscription.address) }}
                    </span>
                </li>
                
                <li class="flex justify-between items-center border-b border-gray-100 dark:border-[#2a2a2a] pb-4">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 shrink-0">Horario (Principal)</span>
                    <span class="font-medium text-sm text-gray-900 dark:text-gray-100 m-0">{{ getHoursSummary(mainBranch?.operating_hours) }}</span>
                </li>

                <li class="flex justify-between items-center border-b border-gray-100 dark:border-[#2a2a2a] pb-4">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 shrink-0">Estatus</span>
                    <Tag v-if="pendingPayment" value="Pago pendiente" severity="warn" :pt="tagPt" />
                    <Tag v-else :value="subscription.status.replace('_', ' ')" :severity="getStatusTagSeverity(subscription.status)" class="capitalize" :pt="tagPt" />
                </li>
                
                <li class="flex justify-between items-center">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 shrink-0">Miembro desde</span>
                    <span class="font-medium text-sm text-gray-900 dark:text-gray-100 m-0">{{ formatDate(subscription.created_at) }}</span>
                </li>
            </ul>
        </div>
    </div>

    <!-- DIALOGO EDICIÓN INFORMACIÓN -->
    <Dialog v-model:visible="isEditModalVisible" modal class="w-full max-w-md mx-4" :pt="dialogPt">
        
        <template #header>
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 text-blue-500 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                    <i class="pi pi-pencil !text-sm"></i>
                </div>
                <div>
                    <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">Editar información</h2>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">Configuración comercial</p>
                </div>
            </div>
        </template>

        <form @submit.prevent="submitInfoForm" class="flex flex-col gap-5 pt-2">
            <div>
                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 block mb-2">Nombre comercial *</span>
                <InputText id="commercial_name" v-model="infoForm.commercial_name" :pt="inputPt" />
                <InputError :message="infoForm.errors.commercial_name" class="mt-1" />
            </div>
            
            <div>
                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 block mb-2">Razón social (opcional)</span>
                <InputText id="business_name" v-model="infoForm.business_name" :pt="inputPt" />
                <InputError :message="infoForm.errors.business_name" class="mt-1" />
            </div>
            
            <div>
                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 block mb-2">Teléfono principal</span>
                <InputText id="contact_phone" v-model="infoForm.contact_phone" :pt="inputPt" />
                <InputError :message="infoForm.errors.contact_phone" class="mt-1" />
            </div>
            
            <div>
                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 block mb-2">Dirección Fiscal / Matriz</span>
                <Textarea id="address" v-model="infoForm.address" rows="2" :pt="textareaPt" />
                <InputError :message="infoForm.errors.address" class="mt-1" />
            </div>
            
            <div class="bg-gray-50 dark:bg-[#1a1a1a] p-4 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400 dark:text-gray-500 block mb-3">Horario de atención</span>
                <Button type="button" label="Configurar Horario Semanal" icon="pi pi-clock" severity="secondary" outlined class="w-full !rounded-xl !text-xs !uppercase !tracking-widest !font-bold" @click="hoursModalVisible = true" />
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-3 m-0 italic text-center">Esto actualizará el horario de tu sucursal principal.</p>
            </div>

            <!-- BOTONES FUERA DE <TEMPLATE> -->
            <div class="flex justify-end gap-3 mt-4 pt-6 border-t border-gray-100 dark:border-[#3a3a3a] w-full">
                <Button type="button" label="Cancelar" text severity="secondary" @click="isEditModalVisible = false" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold" />
                <Button type="submit" label="Guardar cambios" :loading="infoForm.processing" severity="primary" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-6 shadow-sm" />
            </div>
        </form>
    </Dialog>

    <!-- SUB-DIALOGO EDICIÓN HORARIO -->
    <Dialog v-model:visible="hoursModalVisible" modal class="w-full max-w-lg mx-4" :pt="dialogPt">
        
        <template #header>
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-full bg-orange-50 dark:bg-orange-900/20 text-orange-500 flex items-center justify-center flex-shrink-0 border border-orange-100 dark:border-orange-900/30">
                    <i class="pi pi-clock !text-sm"></i>
                </div>
                <div>
                    <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">Horario semanal</h2>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1">Horas de apertura al público</p>
                </div>
            </div>
        </template>

        <div class="pt-2 flex flex-col gap-3">
            <div v-for="(day, dayIndex) in infoForm.operating_hours" :key="day.day" 
                class="bg-gray-50 dark:bg-[#1a1a1a] p-4 rounded-2xl border border-transparent transition-colors flex flex-col sm:flex-row items-center gap-4"
                :class="day.open ? 'border-primary-100 dark:border-primary-900/30' : 'border-gray-100 dark:border-[#3a3a3a] opacity-70'">
                
                <div class="w-full sm:w-1/3 flex items-center gap-3">
                    <Checkbox :id="'day_open_' + dayIndex" v-model="day.open" :binary="true" />
                    <label :for="'day_open_' + dayIndex" class="text-sm font-bold tracking-widest uppercase cursor-pointer m-0" :class="day.open ? 'text-primary-600 dark:text-primary-400' : 'text-gray-500'">{{ day.day }}</label>
                </div>
                
                <div class="w-full sm:w-2/3 flex items-center gap-3">
                    <div class="flex-1 relative">
                        <span class="absolute -top-2.5 left-2 bg-gray-50 dark:bg-[#1a1a1a] px-1 text-[8px] uppercase tracking-widest font-bold text-gray-400 z-10">Apertura</span>
                        <InputMask v-model="day.from" mask="99:99" placeholder="09:00" :disabled="!day.open" :pt="{ root: { class: '!rounded-xl !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2 !text-sm w-full font-mono text-center' } }"/>
                    </div>
                    <span class="text-gray-400 font-bold">-</span>
                    <div class="flex-1 relative">
                        <span class="absolute -top-2.5 left-2 bg-gray-50 dark:bg-[#1a1a1a] px-1 text-[8px] uppercase tracking-widest font-bold text-gray-400 z-10">Cierre</span>
                        <InputMask v-model="day.to" mask="99:99" placeholder="18:00" :disabled="!day.open" :pt="{ root: { class: '!rounded-xl !bg-white dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2 !text-sm w-full font-mono text-center' } }"/>
                    </div>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex justify-end w-full mt-4 pt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
                <Button label="Aplicar horario" icon="pi pi-check" @click="hoursModalVisible = false" severity="primary" class="!rounded-xl !uppercase !tracking-widest !text-xs !font-bold px-8 shadow-sm" />
            </div>
        </template>
    </Dialog>
</template>