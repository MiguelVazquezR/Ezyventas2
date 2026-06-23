<script setup>
defineProps({
    form: { type: Object, required: true },
    inputPt: { type: Object, required: true },
    mpConnected: Boolean,
    mpTestMode: Boolean,
    mpUserId: String,
    mpAccountInfo: Object,
});
</script>

<template>
    <div id="payments" class="bg-white dark:bg-[#232323] p-6 lg:p-8 rounded-3xl border border-gray-100 dark:border-[#3a3a3a] space-y-4">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 m-0 pb-2 border-b border-gray-100 dark:border-[#3a3a3a]">Métodos de pago</h2>

        <!-- Mercado Pago -->
        <div class="flex flex-col gap-1.5">
            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Mercado Pago</label>

            <!-- Test mode banner -->
            <div v-if="mpTestMode" class="flex items-center gap-2 px-3 py-2 bg-amber-50 dark:bg-amber-900/10 rounded-xl border border-amber-200 dark:border-amber-900/30">
                <i class="pi pi-exclamation-triangle !text-xs text-amber-600 dark:text-amber-400" />
                <span class="text-[11px] text-amber-700 dark:text-amber-400 font-medium">Modo de prueba activo — Mercado Pago simulado</span>
            </div>

            <!-- Not connected: show connect button -->
            <div v-if="!mpConnected" class="p-3 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a] flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <img src="/images/Mercado_Pago_logo.png" alt="Mercado Pago" class="h-6 object-contain opacity-60 dark:hidden" />
                    <img src="/images/Mercado_Pago_logo_claro.png" alt="Mercado Pago" class="h-6 object-contain opacity-60 hidden dark:block" />
                    <span class="text-xs text-gray-500">Conecta tu cuenta de Mercado Pago para aceptar pagos en línea.</span>
                </div>
                <a :href="route('online-store.mp.connect')"
                    class="inline-flex items-center gap-1.5 px-4 py-2 rounded-full text-[10px] uppercase tracking-widest font-bold text-white transition-all"
                    style="background: #009EE3;">
                    <i class="pi pi-link !text-xs" />
                    Conectar
                </a>
            </div>

            <!-- Connected: show connection status + account info + toggle + disconnect -->
            <template v-else>
                <!-- Connection status row -->
                <div class="flex items-center justify-between p-3 bg-green-50 dark:bg-green-900/10 rounded-2xl border border-green-100 dark:border-green-900/30">
                    <div class="flex items-center gap-3">
                        <img src="/images/Mercado_Pago_logo.png" alt="Mercado Pago" class="h-7 object-contain dark:hidden" />
                        <img src="/images/Mercado_Pago_logo_claro.png" alt="Mercado Pago" class="h-7 object-contain hidden dark:block" />
                        <span class="text-xs text-green-700 dark:text-green-400">
                            Conectado{{ mpUserId && !mpTestMode ? ' (' + mpUserId + ')' : '' }}
                        </span>
                    </div>
                    <!-- Disconnect: only available in production mode -->
                    <a v-if="!mpTestMode"
                        :href="route('online-store.mp.disconnect')"
                        @click.prevent="$inertia.post(route('online-store.mp.disconnect'))"
                        class="text-[10px] text-red-500 hover:text-red-600 font-medium">
                        Desconectar cuenta
                    </a>
                    <span v-else class="text-[10px] text-gray-400 dark:text-gray-500 italic">
                        No se puede cambiar de cuenta en modo prueba
                    </span>
                </div>

                <!-- Account details -->
                <div v-if="mpAccountInfo" class="p-3 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                    <p class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0 mb-2">Datos de la cuenta</p>
                    <div class="space-y-1">
                        <div v-if="mpAccountInfo.name" class="flex items-center gap-2">
                            <span class="text-[10px] text-gray-400 w-16">Nombre</span>
                            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ mpAccountInfo.name }}</span>
                        </div>
                        <div v-if="mpAccountInfo.country" class="flex items-center gap-2">
                            <span class="text-[10px] text-gray-400 w-16">País</span>
                            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ mpAccountInfo.country }}</span>
                        </div>
                        <div v-if="mpAccountInfo.user_id" class="flex items-center gap-2">
                            <span class="text-[10px] text-gray-400 w-16">User ID</span>
                            <span class="text-xs font-mono text-gray-700 dark:text-gray-300">{{ mpAccountInfo.user_id }}</span>
                        </div>
                    </div>
                </div>

                <!-- Activate/deactivate payment method toggle -->
                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-[#1a1a1a] rounded-2xl border border-gray-100 dark:border-[#3a3a3a]">
                    <div>
                        <span class="text-xs font-medium text-gray-700 dark:text-gray-300">Activar Mercado Pago en la tienda</span>
                        <p class="text-[10px] text-gray-400 dark:text-gray-500 m-0 mt-0.5">Los clientes podrán pagar con Mercado Pago al finalizar la compra.</p>
                    </div>
                    <ToggleSwitch v-model="form.payment_mp_enabled" />
                </div>
            </template>
        </div>

        <!-- Cash on delivery -->
        <div class="flex flex-col gap-1.5">
            <div class="flex items-center justify-between">
                <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Pago en efectivo (contra entrega)</label>
                <ToggleSwitch v-model="form.payment_cash_enabled" />
            </div>
            <p class="text-[11px] text-gray-400 dark:text-gray-500 m-0 leading-relaxed">
                Permite que los clientes paguen en efectivo al recibir su pedido.
            </p>
        </div>

        <div v-if="form.payment_cash_enabled" class="flex flex-col gap-1.5">
            <label class="text-[10px] uppercase tracking-widest font-bold text-gray-500 m-0">Instrucciones para pago en efectivo</label>
            <InputText v-model="form.cash_instructions" :pt="inputPt" class="w-full" placeholder="Ej: Pagar en efectivo al repartidor al momento de la entrega." />
        </div>
    </div>
</template>
