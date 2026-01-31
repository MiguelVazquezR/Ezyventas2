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
        :style="{ width: '90vw', maxWidth: '600px' }"
    >
        <div class="flex flex-col gap-6">
            
            <!-- NUEVA DESCRIPCIÓN INFORMATIVA -->
            <div class="bg-yellow-50 dark:bg-yellow-900/20 p-3 rounded-lg border border-yellow-200 dark:border-yellow-800 text-sm text-yellow-800 dark:text-yellow-200 flex items-start gap-3">
                <i class="pi pi-info-circle mt-0.5 text-lg"></i>
                <div>
                    <p class="font-bold">¿Para qué sirve un pedido?</p>
                    <p class="mt-1 leading-relaxed">
                        Utiliza esta opción para ventas que requieren <strong>entrega a domicilio</strong> o recolección programada. 
                        El inventario se reservará de inmediato, pero el cobro se puede gestionar posteriormente (ej. pago contra entrega).
                    </p>
                </div>
            </div>

            <!-- SECCIÓN 1: Datos de Contacto -->
            <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-lg border border-blue-100 dark:border-blue-800">
                <div class="flex items-center gap-2 mb-3">
                    <i class="pi pi-user text-blue-600 dark:text-blue-400"></i>
                    <h3 class="font-bold text-gray-800 dark:text-gray-200 m-0">Información de contacto</h3>
                </div>
                
                <div v-if="client" class="mb-3 flex items-center gap-2 text-sm text-green-700 dark:text-green-400 bg-green-100 dark:bg-green-900/30 p-2 rounded">
                    <i class="pi pi-check-circle"></i>
                    <span>Cliente registrado seleccionado: <strong>{{ client.name }}</strong></span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="flex flex-col gap-2">
                        <label class="text-sm font-semibold">Nombre de quien recibe *</label>
                        <InputText v-model="form.contact_name" placeholder="Ej. Juan Pérez" class="w-full" :disabled="!!client" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <label class="text-sm font-semibold">Teléfono / WhatsApp *</label>
                        <InputText v-model="form.contact_phone" placeholder="Ej. 55 1234 5678" class="w-full" />
                    </div>
                </div>
            </div>

            <!-- SECCIÓN 2: Logística de Entrega -->
            <div class="border-t pt-4">
                <h3 class="font-bold text-gray-800 dark:text-gray-200 mb-4">Detalles de entrega</h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="flex flex-col gap-2">
                        <label class="text-sm font-semibold">Fecha y hora de entrega *</label>
                        <DatePicker v-model="form.delivery_date" showTime hourFormat="12" dateFormat="dd/mm/yy" placeholder="Selecciona fecha" class="w-full" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <label class="text-sm font-semibold">Costo de envío</label>
                        <InputNumber v-model="form.shipping_cost" mode="currency" currency="MXN" locale="es-MX" class="w-full" placeholder="$0.00" />
                    </div>
                </div>

                <div class="flex flex-col gap-2 mb-4">
                    <label class="text-sm font-semibold">Dirección de entrega</label>
                    <Textarea v-model="form.shipping_address" rows="2" placeholder="Calle, número, colonia y referencias..." class="w-full" />
                </div>

                <div class="flex flex-col gap-2">
                    <label class="text-sm font-semibold">Notas del pedido (Internas)</label>
                    <Textarea v-model="form.notes" rows="2" placeholder="Ej. Empacar en caja de regalo..." class="w-full" />
                </div>
            </div>

            <!-- SECCIÓN 3: Resumen Financiero -->
            <div class="bg-gray-100 dark:bg-gray-800 p-4 rounded-lg flex justify-between items-center">
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    <p>Subtotal Productos: {{ formatCurrency(cartTotal) }}</p>
                    <p>Envío: {{ formatCurrency(form.shipping_cost) }}</p>
                </div>
                <div class="text-right">
                    <p class="text-xs uppercase font-bold text-gray-500">Total del Pedido</p>
                    <p class="text-2xl font-black text-gray-900 dark:text-white">{{ formatCurrency(grandTotal) }}</p>
                </div>
            </div>

        </div>

        <template #footer>
            <div class="flex justify-end gap-2">
                <Button label="Cancelar" severity="secondary" text @click="emit('update:visible', false)" :disabled="loading" />
                <!-- BOTÓN CON ESTADO DE CARGA -->
                <Button 
                    label="Confirmar Pedido" 
                    icon="pi pi-check" 
                    @click="handleSubmit" 
                    :disabled="!isFormValid" 
                    :loading="loading" 
                />
            </div>
        </template>
    </Dialog>
</template>