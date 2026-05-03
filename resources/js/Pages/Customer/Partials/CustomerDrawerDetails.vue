<script setup>

const props = defineProps({
    customer: {
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
    },
    canSeeFinancials: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['go-to-details', 'go-to-edit']);

const getBalanceClass = (balance) => {
    if (balance > 0) return 'text-green-500';
    if (balance < 0) return 'text-red-500';
    return 'text-gray-900 dark:text-white';
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
};

const formatAddress = (address) => {
    if (!address) return 'No registrada';
    if (typeof address === 'string') return address;
    
    const parts = [];
    if (address.street) parts.push(address.street);
    if (address.exterior_number) parts.push(address.exterior_number);
    if (address.neighborhood) parts.push(address.neighborhood);
    if (address.city) parts.push(address.city);
    if (address.state) parts.push(address.state);
    
    return parts.length > 0 ? parts.join(', ') : 'No registrada';
};
</script>

<template>
    <div class="flex flex-col h-full">
        <!-- Scrollable Content -->
        <div class="flex-grow space-y-6 overflow-y-auto pb-6 px-6 pt-6 custom-scrollbar">
            
            <!-- Info Header -->
            <div class="flex items-center gap-4">
                <Avatar 
                    :label="customer.name ? customer.name.substring(0, 1).toUpperCase() : 'C'" 
                    size="xlarge" 
                    shape="circle" 
                    class="!bg-blue-50 !text-blue-600 dark:!bg-blue-900/30 dark:!text-blue-400 font-bold text-2xl border border-blue-100 dark:border-blue-800/50 flex-shrink-0" 
                />
                <div>
                    <h2 class="text-xl font-light tracking-tight text-gray-900 dark:text-white m-0 leading-tight">{{ customer.name }}</h2>
                    <p v-if="customer.company_name" class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mt-1.5 flex items-center gap-1">
                        <i class="pi pi-building !text-[9px]"></i> {{ customer.company_name }}
                    </p>
                </div>
            </div>

            <!-- Contact Info -->
            <div class="space-y-4 bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                <h3 class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest m-0 mb-4">Información de contacto</h3>
                
                <div class="flex items-start gap-4">
                    <div class="w-8 h-8 rounded-full bg-white dark:bg-[#232323] flex items-center justify-center flex-shrink-0 border border-gray-100 dark:border-[#3a3a3a]">
                        <i class="pi pi-phone !text-xs text-gray-400"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100 m-0 tracking-tight">
                            {{ customer.phone || 'No registrado' }}
                        </p>
                        <span class="text-[10px] uppercase tracking-widest text-gray-500 m-0 mt-0.5 block">Teléfono principal</span>
                    </div>
                </div>
                
                <div class="flex items-start gap-4">
                    <div class="w-8 h-8 rounded-full bg-white dark:bg-[#232323] flex items-center justify-center flex-shrink-0 border border-gray-100 dark:border-[#3a3a3a]">
                        <i class="pi pi-envelope !text-xs text-gray-400"></i>
                    </div>
                    <div class="break-all">
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100 m-0 tracking-tight">
                            {{ customer.email || 'No registrado' }}
                        </p>
                        <span class="text-[10px] uppercase tracking-widest text-gray-500 m-0 mt-0.5 block">Correo electrónico</span>
                    </div>
                </div>
                
                <div class="flex items-start gap-4">
                    <div class="w-8 h-8 rounded-full bg-white dark:bg-[#232323] flex items-center justify-center flex-shrink-0 border border-gray-100 dark:border-[#3a3a3a]">
                        <i class="pi pi-map-marker !text-xs text-gray-400"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100 m-0 tracking-tight leading-snug">
                            {{ formatAddress(customer.address) }}
                        </p>
                        <span class="text-[10px] uppercase tracking-widest text-gray-500 m-0 mt-0.5 block">Dirección</span>
                    </div>
                </div>
            </div>

            <!-- Financial Info -->
            <div v-if="canSeeFinancials" class="space-y-4 bg-cyan-50 dark:bg-cyan-900/10 p-5 rounded-2xl border border-cyan-100 dark:border-cyan-900/30">
                <h3 class="text-[10px] font-bold text-cyan-800 dark:text-cyan-500 uppercase tracking-widest m-0 mb-4">Estado financiero</h3>
                
                <div class="flex justify-between items-end border-b border-cyan-100 dark:border-cyan-900/40 pb-3">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-cyan-700 dark:text-cyan-400 m-0">Saldo actual</span>
                    <span class="font-light tracking-tight text-3xl leading-none m-0" :class="getBalanceClass(customer.balance)">
                        {{ formatCurrency(customer.balance) }}
                    </span>
                </div>
                <div class="flex justify-between items-end pt-1">
                    <span class="text-[10px] uppercase tracking-widest text-cyan-600 dark:text-cyan-500 m-0">Límite de crédito</span>
                    <span class="font-mono text-sm text-cyan-900 dark:text-cyan-100 m-0">
                        {{ formatCurrency(customer.credit_limit) }}
                    </span>
                </div>
                <div class="flex justify-between items-end pt-1">
                    <span class="text-[10px] uppercase tracking-widest font-bold text-cyan-700 dark:text-cyan-400 m-0">Crédito disponible</span>
                    <span class="font-mono font-bold text-sm text-cyan-600 dark:text-cyan-400 m-0">
                        {{ formatCurrency(customer.available_credit || 0) }}
                    </span>
                </div>
            </div>
        </div>

        <!-- Actions Footer -->
        <div class="p-6 border-t border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3 bg-white dark:bg-[#232323]">
            <Button 
                v-if="canSeeDetails" 
                label="Ver perfil completo" 
                icon="pi pi-id-card" 
                class="w-full !rounded-xl !uppercase !tracking-widest !text-xs !font-bold" 
                @click="$emit('go-to-details')" 
            />
            <Button 
                v-if="canEdit" 
                label="Editar información" 
                icon="pi pi-pencil" 
                severity="secondary" 
                outlined 
                class="w-full !rounded-xl !uppercase !tracking-widest !text-xs !font-bold" 
                @click="$emit('go-to-edit')" 
            />
        </div>
    </div>
</template>