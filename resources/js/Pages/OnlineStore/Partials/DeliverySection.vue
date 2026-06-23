<script setup>
defineProps({
    form: { type: Object, required: true },
    inputPt: { type: Object, required: true },
});
</script>

<template>
    <div id="delivery" class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] space-y-4">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 m-0 pb-2 border-b border-gray-100 dark:border-[#3a3a3a]">Opciones de entrega</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
            <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl h-full">
                <div class="pr-3">
                    <span class="text-sm font-medium dark:text-white">Aceptar recoger en tienda</span>
                    <p class="text-xs text-gray-400 m-0">Los clientes pueden recoger pedidos en tu ubicación.</p>
                </div>
                <ToggleSwitch v-model="form.accepts_pickup" class="shrink-0" />
            </div>
            <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl h-full">
                <div class="pr-3">
                    <span class="text-sm font-medium dark:text-white">Aceptar envíos a domicilio</span>
                    <p class="text-xs text-gray-400 m-0">Enviar pedidos a la dirección del cliente.</p>
                </div>
                <ToggleSwitch v-model="form.accepts_delivery" class="shrink-0" />
            </div>
        </div>
        <template v-if="form.accepts_delivery">
            <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl">
                <div>
                    <span class="text-sm font-medium dark:text-white">Permitir comprar productos agotados</span>
                    <p class="text-xs text-gray-400 m-0">Los clientes podrán pedir productos sin stock, con tiempo extra de preparación.</p>
                </div>
                <ToggleSwitch v-model="form.allow_out_of_stock_purchases" />
            </div>
            <div v-if="form.allow_out_of_stock_purchases" class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Tiempo extra por resurtimiento</label>
                <div class="grid grid-cols-3 gap-3">
                    <div class="flex flex-col gap-1">
                        <span class="text-[9px] text-gray-400">Días</span>
                        <InputNumber v-model="form.restock_days" :min="0" :max="30" :pt="{ input: { root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-sm !text-center' } } }" />
                    </div>
                    <div class="flex flex-col gap-1">
                        <span class="text-[9px] text-gray-400">Horas</span>
                        <InputNumber v-model="form.restock_hours" :min="0" :max="23" :pt="{ input: { root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-sm !text-center' } } }" />
                    </div>
                    <div class="flex flex-col gap-1">
                        <span class="text-[9px] text-gray-400">Minutos</span>
                        <InputNumber v-model="form.restock_minutes" :min="0" :max="59" :pt="{ input: { root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-sm !text-center' } } }" />
                    </div>
                </div>
                <p class="text-[11px] text-gray-400 dark:text-gray-500 m-0 leading-relaxed">
                    <i class="pi pi-info-circle !text-xs mr-1" />
                    Tiempo adicional de preparación cuando un producto requiere resurtimiento.
                </p>
                <Message v-if="form.errors.out_of_stock_extra_minutes" severity="error" variant="simple" size="small">{{ form.errors.out_of_stock_extra_minutes }}</Message>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Costo de envío</label>
                    <InputNumber v-model="form.delivery_fee" mode="currency" currency="MXN" :pt="{ input: { root: { class: 'w-full !rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-sm' } } }" />
                    <Message v-if="form.errors.delivery_fee" severity="error" variant="simple" size="small">{{ form.errors.delivery_fee }}</Message>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Envío gratis a partir de</label>
                    <InputNumber v-model="form.free_shipping_minimum" mode="currency" currency="MXN" :pt="{ input: { root: { class: 'w-full !rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-sm' } } }" />
                    <p class="text-[11px] text-gray-400 dark:text-gray-500 m-0 leading-relaxed">
                        <i class="pi pi-info-circle !text-xs mr-1" />
                        Si el total del pedido alcanza este monto, el envío será gratis. Deja en 0 para cobrar envío siempre.
                    </p>
                    <Message v-if="form.errors.free_shipping_minimum" severity="error" variant="simple" size="small">{{ form.errors.free_shipping_minimum }}</Message>
                </div>
            </div>
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Tiempo de preparación</label>
                <div class="grid grid-cols-3 gap-3">
                    <div class="flex flex-col gap-1">
                        <span class="text-[9px] text-gray-400">Días</span>
                        <InputNumber v-model="form.prep_days" :min="0" :max="30" :pt="{ input: { root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-sm !text-center' } } }" />
                    </div>
                    <div class="flex flex-col gap-1">
                        <span class="text-[9px] text-gray-400">Horas</span>
                        <InputNumber v-model="form.prep_hours" :min="0" :max="23" :pt="{ input: { root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-sm !text-center' } } }" />
                    </div>
                    <div class="flex flex-col gap-1">
                        <span class="text-[9px] text-gray-400">Minutos</span>
                        <InputNumber v-model="form.prep_minutes" :min="0" :max="59" :pt="{ input: { root: { class: '!rounded-xl !bg-white dark:!bg-[#1a1a1a] !border-gray-200 dark:!border-[#3a3a3a] !text-sm !text-center' } } }" />
                    </div>
                </div>
                <p class="text-[11px] text-gray-400 dark:text-gray-500 m-0 leading-relaxed">
                    <i class="pi pi-info-circle !text-xs mr-1" />
                    Tiempo estimado que tardas en preparar un pedido.
                </p>
            </div>
            <div class="flex flex-col gap-1.5">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Política de envío</label>
                <Textarea v-model="form.delivery_policy" :pt="inputPt" rows="3" class="w-full" />
            </div>
        </template>
    </div>
</template>
