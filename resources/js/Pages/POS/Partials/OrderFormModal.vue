<script setup>
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';

const props = defineProps({
    visible: Boolean,
    cartTotal: Number,
    client: Object, 
    loading: Boolean, // <--- NUEVA PROP para estado de carga
});

const emit = defineEmits(['update:visible', 'submit']);
const toast = useToast();

// --- Estado del Formulario ---
const form = useForm({
    contact_name: '',
    contact_phone: '',
    delivery_date: null,
    shipping_address: '',
    shipping_cost: 0,
    notes: ''
});

// --- Sincronización ---
watch(() => props.visible, (newVal) => {
    if (newVal) {
        if (props.client) {
            form.contact_name = props.client.name;
            form.contact_phone = props.client.phone || '';
            form.shipping_address = props.client.address || ''; 
        } else {
            form.reset();
        }
        form.shipping_cost = 0; 
    }
});

// --- Cálculos ---
const grandTotal = computed(() => {
    return (parseFloat(props.cartTotal) || 0) + (parseFloat(form.shipping_cost) || 0);
});

const isFormValid = computed(() => {
    if (!form.contact_name || form.contact_name.length < 2) return false;
    if (!form.delivery_date) return false;
    return true;
});

// --- Acciones ---
const handleSubmit = () => {
    if (!isFormValid.value) {
        toast.add({ severity: 'warn', summary: 'Datos incompletos', detail: 'El nombre del contacto y la fecha de entrega son obligatorios.', life: 3000 });
        return;
    }

    emit('submit', {
        ...form.data(),
        calculated_total: grandTotal.value 
    });
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
};
</script>

<template>
    <Dialog 
        :visible="visible" 
        @update:visible="val => emit('update:visible', val)"
        modal 
        header="Crear nuevo pedido" 
        class="w-full max-w-2xl"
        :breakpoints="{ '1199px': '75vw', '575px': '95vw' }"
        :pt="{
            root: { class: 'dark:bg-[#232323] border-none shadow-2xl rounded-3xl overflow-hidden' },
            header: { class: 'dark:bg-[#232323] border-b border-gray-100 dark:border-[#3a3a3a] px-6 md:px-8 py-5 md:py-6' },
            title: { class: 'text-xl md:text-2xl font-light tracking-tight text-gray-900 dark:text-white m-0' },
            content: { class: 'dark:bg-[#232323] px-6 md:px-8 py-6' },
            footer: { class: 'dark:bg-[#232323] border-t border-gray-100 dark:border-[#3a3a3a] px-6 md:px-8 py-4 md:py-5' }
        }"
    >
        <div class="flex flex-col gap-8">
            
            <!-- DESCRIPCIÓN INFORMATIVA -->
            <div class="bg-blue-50 dark:bg-blue-900/10 p-5 rounded-2xl border border-blue-100 dark:border-blue-900/30 text-sm flex items-start gap-4">
                <div class="w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-900/40 text-blue-600 dark:text-blue-400 flex items-center justify-center flex-shrink-0">
                    <i class="pi pi-info-circle !text-xl"></i>
                </div>
                <div>
                    <p class="font-medium text-lg text-blue-900 dark:text-blue-300 m-0 mb-1 tracking-tight">¿Para qué sirve un pedido?</p>
                    <p class="m-0 leading-relaxed text-blue-800 dark:text-blue-200/70 text-sm">
                        Reserva el inventario inmediatamente para ventas que requieren <strong class="dark:text-blue-200">entrega a domicilio o recolección programada</strong>. 
                        El cobro se puede gestionar posteriormente.
                    </p>
                </div>
            </div>

            <!-- SECCIÓN 1: Datos de Contacto -->
            <div>
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-8 h-8 rounded-full bg-gray-50 dark:bg-[#1a1a1a] border border-gray-200 dark:border-[#3a3a3a] flex items-center justify-center text-gray-500">
                        <i class="pi pi-user !text-sm"></i>
                    </div>
                    <h3 class="font-medium text-lg text-gray-900 dark:text-white m-0 tracking-tight">Información de contacto</h3>
                </div>
                
                <div v-if="client" class="mb-5 flex items-center gap-3 text-sm text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-900/20 border border-green-100 dark:border-green-900/30 p-3 rounded-2xl">
                    <i class="pi pi-check-circle !text-lg"></i>
                    <span class="m-0">Cliente enlazado: <strong class="font-medium">{{ client.name }}</strong></span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div class="flex flex-col gap-2">
                        <label class="text-[10px] font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Nombre de quien recibe *</label>
                        <InputText v-model="form.contact_name" placeholder="Ej. Juan Pérez" 
                            class="w-full !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3" 
                            :disabled="!!client" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <label class="text-[10px] font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Teléfono / WhatsApp *</label>
                        <InputText v-model="form.contact_phone" placeholder="Ej. 55 1234 5678" 
                            class="w-full !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3" />
                    </div>
                </div>
            </div>

            <hr class="border-gray-100 dark:border-[#3a3a3a] m-0">

            <!-- SECCIÓN 2: Logística de Entrega -->
            <div>
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-8 h-8 rounded-full bg-gray-50 dark:bg-[#1a1a1a] border border-gray-200 dark:border-[#3a3a3a] flex items-center justify-center text-gray-500">
                        <i class="pi pi-map-marker !text-sm"></i>
                    </div>
                    <h3 class="font-medium text-lg text-gray-900 dark:text-white m-0 tracking-tight">Detalles de entrega</h3>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                    <div class="flex flex-col gap-2">
                        <label class="text-[10px] font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Fecha y hora programada *</label>
                        <DatePicker v-model="form.delivery_date" showTime hourFormat="12" dateFormat="dd/mm/yy" placeholder="Selecciona fecha y hora" 
                            :pt="{ 
                                input: { root: { class: 'w-full !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3' } },
                                panel: { class: 'dark:!bg-[#232323] !border-gray-200 dark:!border-[#3a3a3a] !rounded-2xl shadow-xl' }
                            }" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <label class="text-[10px] font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Cargo por envío</label>
                        <InputNumber v-model="form.shipping_cost" mode="currency" currency="MXN" locale="es-MX" placeholder="$0.00" 
                            class="w-full"
                            :pt="{ input: { root: { class: 'w-full !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3' } } }" />
                    </div>
                </div>

                <div class="flex flex-col gap-2 mb-5">
                    <label class="text-[10px] font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Dirección de entrega (Opcional)</label>
                    <Textarea v-model="form.shipping_address" rows="2" placeholder="Calle, número, colonia y referencias..." 
                        class="w-full !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors resize-none !py-3" />
                </div>

                <div class="flex flex-col gap-2">
                    <label class="text-[10px] font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0">Notas internas (Opcional)</label>
                    <Textarea v-model="form.notes" rows="2" placeholder="Ej. Empacar en caja de regalo..." 
                        class="w-full !rounded-2xl !bg-gray-50 dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors resize-none !py-3" />
                </div>
            </div>

            <!-- SECCIÓN 3: Resumen Financiero -->
            <div class="bg-gray-50 dark:bg-[#1a1a1a] p-6 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6 mt-2">
                <div class="space-y-2 w-full sm:w-auto">
                    <div class="flex justify-between sm:justify-start gap-6 items-center">
                        <span class="text-[10px] uppercase tracking-widest text-gray-500 m-0">Productos</span>
                        <span class="font-mono text-sm text-gray-700 dark:text-gray-300 m-0">{{ formatCurrency(cartTotal) }}</span>
                    </div>
                    <div class="flex justify-between sm:justify-start gap-6 items-center">
                        <span class="text-[10px] uppercase tracking-widest text-gray-500 m-0">Envío</span>
                        <span class="font-mono text-sm text-gray-700 dark:text-gray-300 m-0">{{ formatCurrency(form.shipping_cost) }}</span>
                    </div>
                </div>
                
                <div class="text-left sm:text-right w-full sm:w-auto border-t sm:border-t-0 sm:border-l border-gray-200 dark:border-[#3a3a3a] pt-4 sm:pt-0 sm:pl-8">
                    <p class="text-[10px] uppercase tracking-widest text-gray-500 m-0 mb-1">Total del pedido</p>
                    <p class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-none">{{ formatCurrency(grandTotal) }}</p>
                </div>
            </div>

        </div>

        <template #footer>
            <div class="flex items-center justify-end gap-3 w-full">
                <Button label="Cancelar" severity="secondary" text @click="emit('update:visible', false)" :disabled="loading" class="!rounded-xl !uppercase !tracking-widest !text-[11px] !font-bold" />
                <Button 
                    label="Confirmar pedido" 
                    icon="pi pi-check" 
                    @click="handleSubmit" 
                    :disabled="loading || !isFormValid" 
                    :loading="loading" 
                    class="!rounded-xl !uppercase !tracking-widest !text-[11px] !font-bold !py-3 px-8"
                />
            </div>
        </template>
    </Dialog>
</template>