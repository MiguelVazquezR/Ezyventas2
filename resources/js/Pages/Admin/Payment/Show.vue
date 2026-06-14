<script setup>
import { computed } from 'vue';
import { useForm, router, Link } from '@inertiajs/vue3';
import { useConfirm } from 'primevue/useconfirm';
import AppLayout from '@/Layouts/AppLayout.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    payment: Object,
    proofUrl: String, 
    processedItems: Array, 
});

const confirm = useConfirm();

// --- Lógica de Aprobación ---
const approveForm = useForm({});
const approvePayment = () => {
    confirm.require({
        message: '¿Estás seguro de que quieres APROBAR este pago? Esta acción activará la suscripción del cliente de forma inmediata.',
        header: 'Confirmar aprobación',
        icon: 'pi pi-check-circle',
        acceptLabel: 'Sí, aprobar suscripción',
        rejectLabel: 'Cancelar',
        acceptClass: 'p-button-success',
        accept: () => {
            approveForm.post(route('admin.payments.approve', props.payment.id), {
                preserveScroll: true
            });
        }
    });
};

// --- Lógica de Rechazo ---
const rejectForm = useForm({
    rejection_reason: '',
});
const rejectPayment = () => {
    confirm.require({
        message: '¿Estás seguro de que quieres RECHAZAR este pago? El cliente será notificado con el motivo proporcionado.',
        header: 'Confirmar rechazo',
        icon: 'pi pi-times-circle',
        acceptLabel: 'Sí, rechazar pago',
        rejectLabel: 'Cancelar',
        acceptClass: 'p-button-danger',
        accept: () => {
            rejectForm.post(route('admin.payments.reject', props.payment.id), {
                preserveScroll: true
            });
        }
    });
};

// --- Formateadores ---
const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value);
};

const formatDateTime = (dateString) => {
    return new Date(dateString).toLocaleDateString('es-MX', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true
    });
};

// --- TESLA UI PASS-THROUGH (PT) ---
const dataTablePt = {
    root: { class: 'border border-gray-100 dark:border-[#3a3a3a] rounded-2xl overflow-hidden' },
    headerRow: { class: 'bg-gray-50 dark:bg-[#1a1a1a]' },
    headerCell: { class: 'bg-transparent text-[10px] uppercase tracking-widest text-gray-500 font-bold py-3 px-4 border-b border-gray-100 dark:border-[#3a3a3a]' },
    bodyRow: { class: 'dark:bg-[#232323] hover:bg-gray-50 dark:hover:bg-[#1a1a1a] transition-colors text-sm text-gray-700 dark:text-gray-300 group' },
    bodyCell: { class: 'py-3 px-4 border-b border-gray-50 dark:border-[#2a2a2a]' },
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[9px] !uppercase !tracking-widest !font-bold' }
};

const textareaPt = {
    root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-3 !text-sm w-full' }
};
</script>

<template>
    <AppLayout title="Revisar Pago">
        <div class="p-4 md:p-6 lg:p-8 max-w-[1600px] mx-auto space-y-6">
            
            <!-- Breadcrumb / Botón de regreso -->
            <div class="flex items-center">
                <Link :href="route('admin.payments.index')" class="inline-flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors">
                    <i class="pi pi-arrow-left !text-[10px]"></i> Volver a pagos pendientes
                </Link>
            </div>

            <!-- Header de la página -->
            <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6">
                <div class="flex items-center gap-4">
                    <div class="w-14 h-14 rounded-full bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-800/50">
                        <i class="pi pi-search !text-2xl"></i>
                    </div>
                    <div>
                        <h1 class="text-3xl md:text-4xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">Revisar pago</h1>
                        <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-2 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.8)] animate-pulse"></span>
                            Verifica comprobante y autoriza renovación
                        </p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 lg:gap-8">
                
                <!-- Columna Izquierda (Detalles y Conceptos) -->
                <div class="xl:col-span-2 space-y-6 flex flex-col">
                    
                    <!-- Detalles del pago -->
                    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                        <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0 mb-6">Detalles de la transferencia</h2>
                        
                        <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                            <ul class="m-0 p-0 list-none space-y-4">
                                <li class="flex flex-col sm:flex-row justify-between sm:items-center gap-1 sm:gap-4 border-b border-gray-200 dark:border-[#2a2a2a] pb-4">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 shrink-0">Negocio / Suscriptor</span>
                                    <span class="font-medium text-sm text-gray-900 dark:text-white m-0">{{ payment.subscription_version.subscription.commercial_name }}</span>
                                </li>
                                
                                <li class="flex justify-between items-center border-b border-gray-200 dark:border-[#2a2a2a] pb-4">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 shrink-0">Fecha de solicitud</span>
                                    <span class="text-sm text-gray-600 dark:text-gray-400 m-0 flex items-center gap-1.5"><i class="pi pi-calendar !text-[10px]"></i> {{ formatDateTime(payment.created_at) }}</span>
                                </li>
                                
                                <li class="flex justify-between items-center border-b border-gray-200 dark:border-[#2a2a2a] pb-4">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 shrink-0">Método de pago</span>
                                    <Tag :value="payment.payment_method" severity="info" class="capitalize" :pt="tagPt" />
                                </li>

                                <li class="flex justify-between items-center pt-2">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-900 dark:text-gray-100 m-0 shrink-0">Monto a pagar</span>
                                    <div class="flex flex-col items-end gap-1">
                                        <div v-if="payment.referral_discount_pct" class="flex items-center gap-2">
                                            <span class="text-sm text-gray-400 line-through font-mono">{{ formatCurrency(parseFloat(payment.amount) + parseFloat(payment.referral_discount_amount || 0)) }}</span>
                                            <span class="text-[10px] font-bold text-green-600 dark:text-green-400 uppercase tracking-widest bg-green-100 dark:bg-green-900/20 px-2 py-0.5 rounded-full">-{{ payment.referral_discount_pct }}% ref.</span>
                                        </div>
                                        <span class="font-light tracking-tight text-3xl leading-none text-gray-900 dark:text-white m-0">
                                            {{ formatCurrency(payment.amount) }}
                                        </span>
                                    </div>
                                </li>

                                <!-- Desglose de descuento por referido -->
                                <li v-if="payment.referral_discount_pct" class="flex justify-between items-center border-t border-gray-200 dark:border-[#2a2a2a] pt-4 mt-2">
                                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 shrink-0">Descuento aplicado</span>
                                    <div class="flex flex-col items-end gap-0.5">
                                        <span class="text-sm text-gray-600 dark:text-gray-400">{{ payment.referral_discount_pct }}% por código de referido</span>
                                        <span class="text-sm font-medium text-green-600 dark:text-green-400">-{{ formatCurrency(payment.referral_discount_amount || 0) }}</span>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Tabla de Conceptos -->
                    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex-grow">
                        <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0 mb-6">Conceptos del plan contratado</h2>
                        
                        <DataTable :value="processedItems" responsiveLayout="scroll" :pt="dataTablePt">
                            
                            <Column field="name" header="Concepto">
                                <template #body="{ data }">
                                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ data.name }}</span>
                                </template>
                            </Column>
                            
                            <Column header="Cantidad">
                                <template #body="{ data }">
                                    <div v-if="data.status === 'upgraded'" class="flex items-center gap-1.5 font-mono text-sm">
                                        <span class="text-gray-400 line-through">{{ data.previous_quantity }}</span>
                                        <i class="pi pi-arrow-right !text-[10px] text-primary-500"></i>
                                        <span class="font-bold text-primary-600 dark:text-primary-400">{{ data.quantity }}</span>
                                    </div>
                                    <div v-else-if="data.status === 'downgraded'" class="flex items-center gap-1.5 font-mono text-sm">
                                        <span class="text-gray-400 line-through">{{ data.previous_quantity }}</span>
                                        <i class="pi pi-arrow-right !text-[10px] text-orange-500"></i>
                                        <span class="font-bold text-orange-600 dark:text-orange-400">{{ data.quantity }}</span>
                                    </div>
                                    <span v-else class="font-mono text-sm dark:text-gray-300">
                                        {{ data.quantity }}
                                    </span>
                                </template>
                            </Column>
                            
                            <Column header="Estado">
                                <template #body="{ data }">
                                    <Tag v-if="data.status === 'new'" value="Nuevo" severity="success" :pt="tagPt" />
                                    <Tag v-else-if="data.status === 'upgraded'" value="Mejora" severity="info" :pt="tagPt" />
                                    <Tag v-else-if="data.status === 'unchanged'" value="Sin cambio" severity="secondary" :pt="tagPt" />
                                    <Tag v-else-if="data.status === 'downgraded'" value="Reducción" severity="warning" :pt="tagPt" />
                                </template>
                            </Column>
                            
                            <Column field="billing_period" header="Periodo">
                                <template #body="{ data }">
                                    <span class="capitalize text-xs text-gray-600 dark:text-gray-400">{{ data.billing_period }}</span>
                                </template>
                            </Column>
                            
                            <Column header="Precio Unitario" class="text-right">
                                <template #body="{ data }">
                                    <span class="font-mono text-gray-900 dark:text-white">{{ formatCurrency(data.unit_price) }}</span>
                                </template>
                            </Column>
                        </DataTable>
                    </div>

                </div>

                <!-- Columna Derecha (Comprobante y Acciones) -->
                <div class="xl:col-span-1 space-y-6 flex flex-col">
                    
                    <!-- Comprobante de pago -->
                    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a]">
                        <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0 mb-6">Comprobante de pago</h2>
                        
                        <div v-if="proofUrl" class="space-y-4">
                            <div class="bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a] overflow-hidden flex items-center justify-center p-2 relative group">
                                <!-- Envolvemos Image para centrarlo y darle estilo de preview -->
                                <Image :src="proofUrl" alt="Comprobante de pago" preview 
                                    imageClass="w-full h-auto object-contain max-h-[300px] rounded-xl transition-opacity duration-300 group-hover:opacity-90" />
                            </div>
                            
                            <a :href="proofUrl" target="_blank" rel="noopener noreferrer" class="block w-full">
                                <Button label="Abrir en pestaña nueva" icon="pi pi-external-link" outlined severity="secondary" class="!w-full !rounded-xl !uppercase !tracking-widest !text-[10px] !font-bold" />
                            </a>
                        </div>
                        
                        <div v-else class="flex flex-col items-center justify-center text-center py-10 opacity-60 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-dashed border-gray-200 dark:border-[#3a3a3a]">
                            <i class="pi pi-image !text-3xl text-gray-400 mb-3"></i>
                            <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Sin comprobante</p>
                            <p class="text-xs text-gray-400 mt-1">El usuario no adjuntó un archivo válido.</p>
                        </div>
                    </div>

                    <!-- Panel de Acciones Finales -->
                    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex-grow flex flex-col">
                        <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0 mb-6">Acciones de revisión</h2>
                        
                        <div class="space-y-6 flex-grow flex flex-col">
                            <!-- Aprobar -->
                            <div>
                                <Button @click="approvePayment" label="Aprobar pago e iniciar suscripción" icon="pi pi-check"
                                    severity="success" class="!w-full !rounded-xl !uppercase !tracking-widest !text-[10px] !font-bold !py-4 shadow-[0_4px_14px_rgba(34,197,94,0.4)]" :loading="approveForm.processing" />
                            </div>

                            <div class="border-t border-gray-100 dark:border-[#3a3a3a] my-2"></div>

                            <!-- Rechazar -->
                            <div class="bg-red-50 dark:bg-red-900/10 p-5 rounded-2xl border border-red-100 dark:border-red-900/30 flex-grow flex flex-col">
                                <span class="text-[10px] font-bold text-red-600 dark:text-red-400 uppercase tracking-widest m-0 mb-3"><i class="pi pi-times-circle mr-1"></i> Rechazar movimiento</span>
                                
                                <div class="flex-grow flex flex-col gap-3">
                                    <div class="flex flex-col flex-grow">
                                        <label for="rejection_reason" class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-2">Motivo del rechazo (obligatorio) *</label>
                                        <Textarea id="rejection_reason" v-model="rejectForm.rejection_reason" rows="3"
                                            placeholder="Detalla por qué no es válido este pago..."
                                            :pt="textareaPt" :class="{'!border-red-500': rejectForm.errors.rejection_reason}" />
                                        <InputError :message="rejectForm.errors.rejection_reason" class="mt-1" />
                                    </div>

                                    <Button @click="rejectPayment" label="Rechazar y notificar" icon="pi pi-times"
                                        severity="danger" outlined class="!w-full !rounded-xl !uppercase !tracking-widest !text-[10px] !font-bold mt-auto" :disabled="!rejectForm.rejection_reason"
                                        :loading="rejectForm.processing" />
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </AppLayout>
</template>