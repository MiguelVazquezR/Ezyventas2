<script setup>

const props = defineProps({
    quote: {
        type: Object,
        required: true
    },
    canSeeDetails: {
        type: Boolean,
        default: false
    },
    canEdit: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['go-to-details', 'go-to-edit']);

// --- Helpers ---
const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    const userTimezoneOffset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() + userTimezoneOffset).toLocaleDateString('es-MX', { year: 'numeric', month: 'short', day: 'numeric' });
};

const formatCurrency = (value) => {
    const num = Number(value);
    if (isNaN(num)) return '$0.00';
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(num);
};

const getStatusSeverity = (status) => {
    const map = {
        borrador: 'secondary',
        enviado: 'info',
        autorizada: 'success',
        rechazada: 'danger',
        venta_generada: 'success',
        expirada: 'warn',
        cancelada: 'danger'
    };
    return map[status] || 'secondary';
};

const tagPt = {
    root: { class: '!rounded-full !px-3 !py-1 !text-[10px] !uppercase !tracking-widest !font-bold' }
};
</script>

<template>
    <div class="flex flex-col h-full">
        <!-- Scrollable Content -->
        <div class="flex-grow space-y-6 overflow-y-auto pb-6 px-6 pt-6 custom-scrollbar">
            
            <!-- Info Header -->
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-full bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-800/50">
                    <i class="pi pi-file !text-2xl"></i>
                </div>
                <div>
                    <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">Cotización {{ quote.folio }}</h2>
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1.5 flex items-center gap-1">
                        <i class="pi pi-calendar !text-[9px]"></i> Creada: {{ formatDate(quote.created_at) }}
                    </p>
                </div>
            </div>

            <!-- Estatus -->
            <div class="space-y-4 bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <div class="flex justify-between items-center">
                    <h3 class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest m-0">Estatus Operativo</h3>
                    <Tag 
                        :value="quote.status.replace('_', ' ')" 
                        :severity="getStatusSeverity(quote.status)" 
                        class="capitalize"
                        :pt="tagPt"
                    />
                </div>
            </div>

            <!-- Datos del Cliente -->
            <div class="space-y-4 bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <h3 class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest m-0 mb-4">Información del Cliente</h3>
                
                <div class="flex items-start gap-4 border-b border-gray-200 dark:border-[#2a2a2a] pb-3">
                    <div class="w-8 h-8 rounded-full bg-white dark:bg-[#232323] flex items-center justify-center flex-shrink-0 border border-gray-100 dark:border-[#3a3a3a]">
                        <i class="pi pi-user !text-xs text-gray-400"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white m-0 tracking-tight leading-snug">
                            {{ quote.customer ? quote.customer.name : (quote.recipient_name || 'No especificado') }}
                        </p>
                        <span class="text-[10px] uppercase tracking-widest text-gray-500 m-0 mt-0.5 block">Cliente / Destinatario</span>
                    </div>
                </div>

                <div v-if="quote.expiry_date" class="flex items-start gap-4 pt-1">
                    <div class="w-8 h-8 rounded-full bg-white dark:bg-[#232323] flex items-center justify-center flex-shrink-0 border border-gray-100 dark:border-[#3a3a3a]">
                        <i class="pi pi-calendar-times !text-xs text-gray-400"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white m-0 tracking-tight leading-snug">
                            {{ formatDate(quote.expiry_date) }}
                        </p>
                        <span class="text-[10px] uppercase tracking-widest text-gray-500 m-0 mt-0.5 block">Fecha de vencimiento</span>
                    </div>
                </div>
            </div>

            <!-- Desglose Financiero -->
            <div class="space-y-3 bg-blue-50 dark:bg-blue-900/10 p-5 rounded-2xl border border-blue-100 dark:border-blue-900/30">
                <h3 class="text-[10px] font-bold text-blue-500 dark:text-blue-400 uppercase tracking-widest m-0 mb-4">Desglose Financiero</h3>
                
                <div class="flex justify-between items-center text-sm text-gray-600 dark:text-gray-400">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Subtotal</span>
                    <span class="font-mono">{{ formatCurrency(quote.subtotal) }}</span>
                </div>
                
                <div v-if="quote.total_discount > 0" class="flex justify-between items-center text-sm text-red-500 dark:text-red-400">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Descuento</span>
                    <span class="font-mono">- {{ formatCurrency(quote.total_discount) }}</span>
                </div>

                <div v-if="quote.total_tax > 0" class="flex justify-between items-center text-sm text-gray-600 dark:text-gray-400">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Impuestos</span>
                    <span class="font-mono">+ {{ formatCurrency(quote.total_tax) }}</span>
                </div>

                <div v-if="quote.shipping_cost > 0" class="flex justify-between items-center text-sm text-gray-600 dark:text-gray-400">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Envío / Visita</span>
                    <span class="font-mono">+ {{ formatCurrency(quote.shipping_cost) }}</span>
                </div>

                <div class="flex justify-between items-center border-t border-blue-200 dark:border-blue-800/50 pt-4 mt-2">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Total neto</span>
                    <span class="font-light tracking-tight text-3xl leading-none m-0 text-blue-600 dark:text-blue-400">
                        {{ formatCurrency(quote.total_amount) }}
                    </span>
                </div>
            </div>
        </div>

        <!-- Actions Footer -->
        <div class="p-6 border-t border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3 bg-white dark:bg-[#232323]">
            <Button 
                v-if="canSeeDetails" 
                label="Ver detalles completos" 
                icon="pi pi-eye" 
                class="w-full !rounded-xl !uppercase !tracking-widest !text-xs !font-bold" 
                @click="$emit('go-to-details')" 
            />
            <Button 
                v-if="canEdit && ['borrador', 'enviado', 'autorizada'].includes(quote.status)" 
                label="Editar cotización" 
                icon="pi pi-pencil" 
                severity="secondary" 
                outlined 
                class="w-full !rounded-xl !uppercase !tracking-widest !text-xs !font-bold" 
                @click="$emit('go-to-edit')" 
            />
        </div>
    </div>
</template>