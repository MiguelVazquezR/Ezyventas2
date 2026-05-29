<script setup>
import InputError from '@/Components/InputError.vue';

defineProps({
    form: Object,
    saving: Boolean,
});

const emit = defineEmits(['save-step', 'go-back']);

// --- Tesla UI PT ---
const inputNumberPt = {
    input: {
        root: {
            class: 'w-full !rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] focus:dark:!border-primary-500 transition-colors !py-2.5 !text-sm !text-gray-900 dark:!text-white',
        },
    },
};
</script>

<template>
    <div class="p-5 lg:p-6 space-y-6">
        
        <!-- Info message -->
        <Message severity="info" :closable="false" class="!rounded-xl !text-xs" :pt="{ content: { class: '!text-xs' } }">
            Establece los límites totales para tu suscripción. Éstos se compartirán entre todas tus sucursales.
        </Message>

        <!-- Grid de límites -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            
            <!-- Usuarios -->
            <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-primary-500/10 flex items-center justify-center flex-shrink-0">
                        <i class="pi pi-users text-primary-500 !text-lg"></i>
                    </div>
                    <div>
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Usuarios</label>
                        <p class="text-[10px] text-gray-400 m-0 mt-0.5">Cuentas que podrán acceder al sistema</p>
                    </div>
                </div>
                <InputNumber v-model="form.limits.limit_users" :min="1" showButtons fluid :pt="inputNumberPt" />
                <InputError :message="form.errors['limits.limit_users']" />
            </div>

            <!-- Productos -->
            <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-primary-500/10 flex items-center justify-center flex-shrink-0">
                        <i class="pi pi-barcode text-primary-500 !text-lg"></i>
                    </div>
                    <div>
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Productos</label>
                        <p class="text-[10px] text-gray-400 m-0 mt-0.5">Capacidad para registrar tu inventario</p>
                    </div>
                </div>
                <InputNumber v-model="form.limits.limit_products" :min="1" showButtons fluid :pt="inputNumberPt" />
                <InputError :message="form.errors['limits.limit_products']" />
            </div>

            <!-- Cajas registradoras -->
            <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-primary-500/10 flex items-center justify-center flex-shrink-0">
                        <i class="pi pi-inbox text-primary-500 !text-lg"></i>
                    </div>
                    <div>
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Cajas registradoras</label>
                        <p class="text-[10px] text-gray-400 m-0 mt-0.5">Cajas operando simultáneamente</p>
                    </div>
                </div>
                <InputNumber v-model="form.limits.limit_cash_registers" :min="1" showButtons fluid :pt="inputNumberPt" />
                <InputError :message="form.errors['limits.limit_cash_registers']" />
            </div>

            <!-- Plantillas de impresión -->
            <div class="bg-gray-50 dark:bg-[#1a1a1a] p-5 rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex flex-col gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-primary-500/10 flex items-center justify-center flex-shrink-0">
                        <i class="pi pi-palette text-primary-500 !text-lg"></i>
                    </div>
                    <div>
                        <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Plantillas de impresión</label>
                        <p class="text-[10px] text-gray-400 m-0 mt-0.5">Diseños de tickets o etiquetas</p>
                    </div>
                </div>
                <InputNumber v-model="form.limits.limit_print_templates" :min="1" showButtons fluid :pt="inputNumberPt" />
                <InputError :message="form.errors['limits.limit_print_templates']" />
            </div>

        </div>

        <!-- Navegación -->
        <div class="flex justify-between pt-2">
            <Button label="Anterior" icon="pi pi-arrow-left" severity="secondary" outlined
                @click="emit('go-back')" class="!rounded-full !text-xs !uppercase !tracking-wider" />
            <Button label="Siguiente" icon="pi pi-arrow-right" iconPos="right"
                @click="emit('save-step', 1)" :loading="saving || form.processing"
                class="!rounded-full !text-xs !uppercase !tracking-wider" />
        </div>
    </div>
</template>
