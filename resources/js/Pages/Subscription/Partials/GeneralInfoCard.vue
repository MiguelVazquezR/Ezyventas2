<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

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
const getStatusTagSeverity = (status) => ({ activo: 'success', expirado: 'warning', suspendido: 'danger' })[status] || 'info';

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
</script>

<template>
    <Card>
        <template #title>
            <div class="flex justify-between items-center">
                <span>Información general</span>
                <Button icon="pi pi-pencil" text rounded @click="openEditInfoModal"
                    v-tooltip.bottom="'Editar información'" />
            </div>
        </template>
        <template #content>
            <div class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-500">Nombre comercial:</span>
                    <span class="font-semibold">{{ subscription.commercial_name }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Razón social:</span>
                    <span class="font-semibold">{{ subscription.business_name || 'N/A' }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Teléfono:</span>
                    <span class="font-semibold">{{ subscription.contact_phone || 'N/A' }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Dirección:</span>
                    <span class="font-semibold text-right max-w-[50%] truncate" :title="getAddressText(subscription.address)">
                        {{ getAddressText(subscription.address) }}
                    </span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Horario (Principal):</span>
                    <span class="font-semibold">{{ getHoursSummary(mainBranch?.operating_hours) }}</span>
                </div>

                <div class="flex justify-between items-center pt-2 border-t dark:border-gray-700">
                    <span class="text-gray-500">Estatus:</span>
                    <Tag v-if="pendingPayment" value="Pago pendiente" severity="warn" />
                    <Tag v-else :value="subscription.status"
                        :severity="getStatusTagSeverity(subscription.status)" class="capitalize" />
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Miembro desde:</span>
                    <span class="font-semibold">{{ formatDate(subscription.created_at) }}</span>
                </div>
            </div>
        </template>
    </Card>

    <!-- DIALOGO EDICIÓN INFORMACIÓN -->
    <Dialog v-model:visible="isEditModalVisible" modal header="Editar información general" :style="{ width: '30rem' }">
        <form @submit.prevent="submitInfoForm" class="p-2 space-y-4">
            <div>
                <InputLabel for="commercial_name" value="Nombre comercial *" />
                <InputText id="commercial_name" v-model="infoForm.commercial_name" class="w-full mt-1" />
                <InputError :message="infoForm.errors.commercial_name" />
            </div>
            <div>
                <InputLabel for="business_name" value="Razón social (opcional)" />
                <InputText id="business_name" v-model="infoForm.business_name" class="w-full mt-1" />
                <InputError :message="infoForm.errors.business_name" />
            </div>
            <div>
                <InputLabel for="contact_phone" value="Teléfono principal" />
                <InputText id="contact_phone" v-model="infoForm.contact_phone" class="w-full mt-1" />
                <InputError :message="infoForm.errors.contact_phone" />
            </div>
            <div>
                <InputLabel for="address" value="Dirección Fiscal / Matriz" />
                <Textarea id="address" v-model="infoForm.address" rows="2" class="w-full mt-1" />
                <InputError :message="infoForm.errors.address" />
            </div>
            <div>
                <InputLabel value="Horario de atención" />
                <Button type="button" label="Configurar Horario" icon="pi pi-clock" outlined class="w-full mt-1"
                    @click="hoursModalVisible = true" />
                <small class="text-gray-500">Esto actualizará el horario de tu sucursal principal.</small>
            </div>

            <div class="flex justify-end gap-2 mt-4">
                <Button type="button" label="Cancelar" severity="secondary" @click="isEditModalVisible = false" text />
                <Button type="submit" label="Guardar cambios" :loading="infoForm.processing" />
            </div>
        </form>
    </Dialog>

    <!-- SUB-DIALOGO EDICIÓN HORARIO -->
    <Dialog v-model:visible="hoursModalVisible" modal header="Establecer horario semanal" :style="{ width: '38rem' }">
        <div class="space-y-4 p-2">
            <div v-for="(day, dayIndex) in infoForm.operating_hours" :key="day.day" class="flex items-center gap-4">
                <div class="w-40">
                    <Checkbox :id="'day_open_' + dayIndex" v-model="day.open" :binary="true" />
                    <label :for="'day_open_' + dayIndex" class="ml-2 font-semibold cursor-pointer">{{ day.day }}</label>
                </div>
                <div class="flex-1">
                    <InputMask v-model="day.from" mask="99:99" placeholder="09:00" :disabled="!day.open" class="w-full"/>
                </div>
                <span>-</span>
                <div class="flex-1">
                    <InputMask v-model="day.to" mask="99:99" placeholder="18:00" :disabled="!day.open" class="w-full"/>
                </div>
            </div>
        </div>
        <template #footer>
            <Button label="Listo" icon="pi pi-check" @click="hoursModalVisible = false" />
        </template>
    </Dialog>
</template>