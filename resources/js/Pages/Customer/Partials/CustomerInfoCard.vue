<script setup>
import { computed } from 'vue';
import Button from 'primevue/button';

const props = defineProps({
    customer: {
        type: Object,
        required: true
    }
});

const sanitizePhone = (phone) => {
    if (!phone) return '';
    return phone.replace(/\D/g, ''); 
};

const hasAddress = computed(() => {
    return props.customer.address && (props.customer.address.street || props.customer.address.city);
});

const formattedAddress = computed(() => {
    if (!hasAddress.value) return 'Sin dirección registrada';
    const a = props.customer.address;
    
    let parts = [];
    if (a.street) parts.push(a.street);
    if (a.exterior_number) parts.push(`#${a.exterior_number}`);
    if (a.interior_number) parts.push(`Int. ${a.interior_number}`);
    if (a.neighborhood) parts.push(`Col. ${a.neighborhood}`);
    if (a.city) parts.push(a.city);
    if (a.state) parts.push(a.state);
    if (a.zip_code) parts.push(`CP ${a.zip_code}`);
    
    return parts.join(', ');
});

const googleMapsUrl = computed(() => {
    if (!hasAddress.value) return '#';
    const a = props.customer.address;
    
    const queryParts = [
        a.street,
        a.exterior_number,
        a.neighborhood,
        a.city,
        a.state,
        a.zip_code
    ].filter(part => part).join(' ');
    
    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(queryParts)}`;
});

// ──────────────────────────────────────
// Fiscal / billing data
// ──────────────────────────────────────
const taxRegimeLabels = {
    '601': 'General de Ley Personas Morales',
    '603': 'Personas Morales con Fines no Lucrativos',
    '612': 'Personas Físicas con Actividades Empresariales',
    '616': 'Sin obligaciones fiscales',
    '621': 'Incorporación Fiscal',
    '626': 'Régimen Simplificado de Confianza',
};

const fiscalRegime = computed(() => {
    const c = props.customer;
    const fa = c.fiscal_address;
    if (fa && typeof fa === 'object' && !Array.isArray(fa)) {
        return fa.tax_regime || fa.regimen_fiscal || c.tax_regime || '';
    }
    return c.tax_regime || '';
});

const fiscalRegimeLabel = computed(() => {
    return taxRegimeLabels[fiscalRegime.value] || fiscalRegime.value;
});

const fiscalPostalCode = computed(() => {
    const c = props.customer;
    const fa = c.fiscal_address;
    if (fa && typeof fa === 'object' && !Array.isArray(fa)) {
        return fa.zip_code || fa.postal_code || fa.cp || '';
    }
    const addr = c.address;
    if (addr && typeof addr === 'object' && !Array.isArray(addr)) {
        return addr.zip_code || addr.postal_code || '';
    }
    return '';
});

const hasFiscalData = computed(() => {
    return !!(props.customer.tax_id || props.customer.company_name || fiscalRegime.value || fiscalPostalCode.value);
});
</script>

<template>
    <div class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-6">
        
        <!-- Contacto -->
        <div>
            <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0 mb-4 flex items-center gap-2">
                <i class="pi pi-id-card !text-[10px]"></i> Información de contacto
            </h2>
            
            <ul class="m-0 p-0 list-none space-y-4">
                <li v-if="customer.phone" class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-gray-100 dark:border-[#2a2a2a] pb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-gray-50 dark:bg-[#1a1a1a] flex items-center justify-center flex-shrink-0 border border-gray-200 dark:border-[#3a3a3a]">
                            <i class="pi pi-phone !text-[10px] text-gray-400"></i>
                        </div>
                        <span class="font-medium text-sm text-gray-900 dark:text-white m-0">{{ customer.phone }}</span>
                    </div>
                    <div class="flex gap-2 pl-11 sm:pl-0">
                        <a :href="`https://wa.me/${sanitizePhone(customer.phone)}`" target="_blank" rel="noopener noreferrer">
                            <Button icon="pi pi-whatsapp" rounded text class="!bg-green-50 dark:!bg-green-900/20 !text-green-600 dark:!text-green-400 hover:!bg-green-100 dark:hover:!bg-green-900/40 !w-8 !h-8 !p-0" v-tooltip.top="'Enviar WhatsApp'" />
                        </a>
                        <a :href="`tel:${sanitizePhone(customer.phone)}`">
                            <Button icon="pi pi-phone" rounded text class="!bg-blue-50 dark:!bg-blue-900/20 !text-blue-600 dark:!text-blue-400 hover:!bg-blue-100 dark:hover:!bg-blue-900/40 !w-8 !h-8 !p-0" v-tooltip.top="'Llamar'" />
                        </a>
                    </div>
                </li>
                
                <li v-if="customer.email" class="flex items-center gap-3 border-b border-gray-100 dark:border-[#2a2a2a] pb-4">
                    <div class="w-8 h-8 rounded-full bg-gray-50 dark:bg-[#1a1a1a] flex items-center justify-center flex-shrink-0 border border-gray-200 dark:border-[#3a3a3a]">
                        <i class="pi pi-envelope !text-[10px] text-gray-400"></i>
                    </div>
                    <span class="font-medium text-sm text-gray-900 dark:text-white m-0 break-all">{{ customer.email }}</span>
                </li>
                
            </ul>
        </div>

        <!-- Domicilio -->
        <div v-if="hasAddress" class="pt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0 flex items-center gap-2">
                    <i class="pi pi-map-marker !text-[10px]"></i> Domicilio
                </h2>
                <a :href="googleMapsUrl" target="_blank" rel="noopener noreferrer">
                    <Button icon="pi pi-external-link" label="Ver mapa" size="small" text class="!rounded-xl !uppercase !tracking-widest !text-[10px] !font-bold !py-1 !px-3 bg-gray-50 dark:bg-[#1a1a1a]" />
                </a>
            </div>
            
            <div class="space-y-3">
                <p class="text-sm font-medium text-gray-900 dark:text-gray-200 m-0 leading-relaxed">
                    {{ formattedAddress }}
                </p>
                
                <div v-if="customer.address.cross_streets" class="bg-gray-50 dark:bg-[#1a1a1a] p-4 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                    <span class="text-[9px] uppercase tracking-widest font-bold text-gray-500 block mb-1 m-0">Referencias de ubicación:</span>
                    <p class="text-xs text-gray-700 dark:text-gray-300 m-0 italic">{{ customer.address.cross_streets }}</p>
                </div>
            </div>
        </div>

        <!-- Datos de facturación -->
        <div v-if="hasFiscalData" class="pt-6 border-t border-gray-100 dark:border-[#3a3a3a]">
            <h2 class="text-xs font-bold text-gray-400 dark:text-gray-500 tracking-widest uppercase m-0 mb-4 flex items-center gap-2">
                <i class="pi pi-receipt !text-[10px]"></i> Datos de facturación
            </h2>

            <ul class="m-0 p-0 list-none space-y-4">
                <li v-if="customer.company_name" class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center flex-shrink-0 border border-emerald-100 dark:border-emerald-900/30">
                        <i class="pi pi-building !text-[10px] text-emerald-500"></i>
                    </div>
                    <div class="flex flex-col">
                        <span class="font-medium text-sm text-gray-900 dark:text-white m-0">{{ customer.company_name }}</span>
                        <span class="text-[10px] uppercase tracking-widest text-gray-500 m-0 mt-0.5">Razón social</span>
                    </div>
                </li>

                <li v-if="customer.tax_id" class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0 border border-blue-100 dark:border-blue-900/30">
                        <i class="pi pi-hashtag !text-[10px] text-blue-500"></i>
                    </div>
                    <div class="flex flex-col">
                        <span class="font-mono font-medium text-sm text-gray-900 dark:text-white m-0">{{ customer.tax_id }}</span>
                        <span class="text-[10px] uppercase tracking-widest text-gray-500 m-0 mt-0.5">RFC</span>
                    </div>
                </li>

                <li v-if="fiscalRegime" class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-amber-50 dark:bg-amber-900/20 flex items-center justify-center flex-shrink-0 border border-amber-100 dark:border-amber-900/30">
                        <i class="pi pi-book !text-[10px] text-amber-500"></i>
                    </div>
                    <div class="flex flex-col">
                        <span class="font-medium text-sm text-gray-900 dark:text-white m-0">{{ fiscalRegimeLabel }}</span>
                        <span class="text-[10px] uppercase tracking-widest text-gray-500 m-0 mt-0.5">Régimen fiscal</span>
                    </div>
                </li>

                <li v-if="fiscalPostalCode" class="flex items-center gap-3 pb-2">
                    <div class="w-8 h-8 rounded-full bg-rose-50 dark:bg-rose-900/20 flex items-center justify-center flex-shrink-0 border border-rose-100 dark:border-rose-900/30">
                        <i class="pi pi-map !text-[10px] text-rose-500"></i>
                    </div>
                    <div class="flex flex-col">
                        <span class="font-mono font-medium text-sm text-gray-900 dark:text-white m-0">{{ fiscalPostalCode }}</span>
                        <span class="text-[10px] uppercase tracking-widest text-gray-500 m-0 mt-0.5">Código postal fiscal</span>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</template>